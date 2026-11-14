<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Service;

use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Repository\BoardRepository;
use CajeerEngine\Forum\Security\ForumAccessPolicy;
use CajeerEngine\Forum\Validation\ForumValidator as V;
use CajeerEngine\Support\Uuid;

/** All topology mutations use one transaction and the same tree lock. */
final readonly class NodeService
{
    public const MAX_DEPTH=16;
    public function __construct(private ForumTransaction $tx,private BoardRepository $boards) {}

    private function change(ForumAccessPolicy $actor,string $action,callable $fn): string
    {
        $actor->require('*');
        return $this->tx->write(function () use ($actor,$action,$fn) {
            $this->boards->lockTree();
            $id=$fn();
            $this->tx->audit('forum.node.'.$action,$actor->userId,['id'=>$id]);
            return $id;
        });
    }
    private function categoryRow(string $id): array { return $this->boards->lockCategory(V::id($id)) ?? throw new ForumException('Категория не найдена.',404); }
    private function boardRow(string $id): array { return $this->boards->find(V::id($id)) ?? throw new ForumException('Раздел не найден.',404); }
    private function index(?int $position,int $count): int { if ($position!==null && ($position<0 || $position>$count)) throw new ForumException('Недопустимая позиция.',422); return $position??$count; }
    private function sortCategories(string $id,?int $position): void
    {
        $ids=array_values(array_filter(array_column($this->boards->categories(),'id'),fn($x)=>$x!==$id));
        array_splice($ids,$this->index($position,count($ids)),0,[$id]);
        foreach ($ids as $i=>$value) $this->boards->categoryPosition($value,$i);
    }
    private function sortBoards(string $category,?string $parent,?string $id,?int $position): void
    {
        $ids=[];
        foreach ($this->boards->topology() as $b) if ($b['category_id']===$category && $b['parent_id']===$parent && $b['id']!==$id) $ids[]=$b['id'];
        if ($id!==null) array_splice($ids,$this->index($position,count($ids)),0,[$id]);
        foreach ($ids as $i=>$value) $this->boards->boardPosition($value,$i);
    }
    private function validateType(string $type,string $state): void
    {
        if (!in_array($type,['forum','container'],true) || !in_array($state,['open','readonly','archived'],true)) throw new ForumException('Недопустимый тип или состояние раздела.',422);
    }
    /** Validate the complete proposed graph before changing any row. */
    public static function validateTree(array $rows): void
    {
        $map=array_column($rows,null,'id');
        foreach ($map as $id=>$b) {
            $seen=[]; $current=$b;
            while (true) {
                if (isset($seen[$current['id']]) || count($seen)>=self::MAX_DEPTH) throw new ForumException('Цикл или превышена глубина дерева (16).',409);
                $seen[$current['id']]=true;
                $parent=$current['parent_id'];
                if ($parent===null) break;
                if (!isset($map[$parent]) || $map[$parent]['category_id']!==$b['category_id']) throw new ForumException('Родитель должен находиться в той же категории.',409);
                $current=$map[$parent];
            }
        }
    }
    public function createCategory(ForumAccessPolicy $actor,string $name,string $description): string
    {
        $name=V::text($name,'name',120);$description=V::text($description,'description',1000,0);
        return $this->change($actor,'category.create',function () use ($name,$description) {
            $id=Uuid::v4();$this->boards->insertCategory($id,$name,$description);$this->sortCategories($id,null);return $id;
        });
    }
    public function updateCategory(ForumAccessPolicy $actor,string $id,string $name,string $description,?int $position): string
    {
        $name=V::text($name,'name',120);$description=V::text($description,'description',1000,0);
        return $this->change($actor,'category.update',function () use ($id,$name,$description,$position) {
            $this->categoryRow($id);$this->boards->updateCategory($id,$name,$description);if ($position!==null) $this->sortCategories($id,$position);return $id;
        });
    }
    public function createBoard(ForumAccessPolicy $actor,string $category,string $name,string $description,?string $parent=null,string $type='forum',string $state='open',?int $position=null,string $rules=''): string
    {
        $name=V::text($name,'name',120);$description=V::text($description,'description',1000,0);$this->validateType($type,$state);$rules=V::text($rules,'rules',4000,0);
        return $this->change($actor,'board.create',function () use ($category,$name,$description,$parent,$type,$state,$position,$rules) {
            $this->categoryRow($category);if ($parent!==null) $this->boardRow($parent);
            $id=Uuid::v4();$rows=$this->boards->topology();$rows[]=['id'=>$id,'category_id'=>$category,'parent_id'=>$parent];self::validateTree($rows);
            $this->boards->insertBoard($id,$category,$name,$description);$this->boards->moveBoard($id,$category,$parent);$this->boards->updateBoard($id,$name,$description,$type,$state,$rules);$this->sortBoards($category,$parent,$id,$position);return $id;
        });
    }
    public function updateBoard(ForumAccessPolicy $actor,string $id,string $name,string $description,string $type,string $state,?string $rules=null): string
    {
        $name=V::text($name,'name',120);$description=V::text($description,'description',1000,0);$this->validateType($type,$state);if($rules!==null)$rules=V::text($rules,'rules',4000,0);
        return $this->change($actor,'board.update',function () use ($id,$name,$description,$type,$state,$rules) {
            $this->boardRow($id);if ($type==='container' && $this->boards->hasThreads($id)) throw new ForumException('Раздел содержит темы.',409);
            $this->boards->updateBoard($id,$name,$description,$type,$state,$rules);return $id;
        });
    }
    public function move(ForumAccessPolicy $actor,string $id,string $category,?string $parent,?int $position): string
    {
        return $this->change($actor,'board.move',function () use ($id,$category,$parent,$position) {
            $old=$this->boardRow($id);$this->categoryRow($category);if ($parent!==null) $this->boardRow($parent);
            $rows=array_column($this->boards->topology(),null,'id');$subtree=[$id=>true];
            // Bounded traversal even if externally corrupted data contains a cycle.
            do {$changed=false;foreach ($rows as $key=>$row) if (!isset($subtree[$key]) && isset($subtree[$row['parent_id']??''])) {$subtree[$key]=true;$changed=true;}} while ($changed);
            foreach ($subtree as $key=>$_) $rows[$key]['category_id']=$category;
            $rows[$id]['parent_id']=$parent;self::validateTree(array_values($rows));
            foreach ($subtree as $key=>$_) $this->boards->moveBoard($key,$category,$rows[$key]['parent_id']);
            if ($old['category_id']!==$category || $old['parent_id']!==$parent) $this->sortBoards($old['category_id'],$old['parent_id'],null,null);
            $this->sortBoards($category,$parent,$id,$position);return $id;
        });
    }
    public function delete(ForumAccessPolicy $actor,string $id,bool $category): string
    {
        return $this->change($actor,$category?'category.delete':'board.delete',function () use ($id,$category) {
            $old=$category?$this->categoryRow($id):$this->boardRow($id);
            foreach ($this->boards->topology() as $row) if ($category?$row['category_id']===$id:$row['parent_id']===$id) throw new ForumException('Сначала переместите или удалите подразделы.',409);
            if ($category) $this->boards->deleteCategory($id);
            else {if ($this->boards->hasThreads($id)) throw new ForumException('Нельзя удалить раздел с темами.',409);$this->boards->deleteBoard($id);$this->sortBoards($old['category_id'],$old['parent_id'],null,null);}
            return $id;
        });
    }
}
