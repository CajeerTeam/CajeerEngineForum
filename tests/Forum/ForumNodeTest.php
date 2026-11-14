<?php

declare(strict_types=1);
namespace CajeerEngine\Tests\Forum;

use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Repository\BoardRepository;
use CajeerEngine\Forum\Service\NodeService;
use PHPUnit\Framework\TestCase;

final class ForumNodeTest extends TestCase
{
    private function chain(int $depth): array
    {
        $rows=[];
        for($i=1;$i<=$depth;$i++) $rows[]=['id'=>'b'.$i,'category_id'=>'c','parent_id'=>$i===1?null:'b'.($i-1),'state'=>'open'];
        return $rows;
    }
    public function testDepthLimitAndInheritedState(): void
    {
        $rows=$this->chain(NodeService::MAX_DEPTH);
        NodeService::validateTree($rows);
        $rows[0]['state']='readonly';$rows[4]['state']='archived';
        self::assertSame('archived',BoardRepository::effectiveState($rows[count($rows)-1],array_column($rows,null,'id')));
        $rows[4]['state']='open';
        self::assertSame('readonly',BoardRepository::effectiveState($rows[count($rows)-1],array_column($rows,null,'id')));
        $this->expectException(ForumException::class);
        NodeService::validateTree($this->chain(NodeService::MAX_DEPTH+1));
    }
    public function testCycleIsRejected(): void
    {
        $rows=$this->chain(3);$rows[0]['parent_id']='b3';
        $this->expectException(ForumException::class);NodeService::validateTree($rows);
    }
    public function testCrossCategoryParentIsRejected(): void
    {
        $rows=$this->chain(2);$rows[1]['category_id']='other';
        $this->expectException(ForumException::class);NodeService::validateTree($rows);
    }
    public function testMissingParentIsRejected(): void
    {
        $rows=$this->chain(2);$rows[1]['parent_id']='missing';
        $this->expectException(ForumException::class);NodeService::validateTree($rows);
    }
}
