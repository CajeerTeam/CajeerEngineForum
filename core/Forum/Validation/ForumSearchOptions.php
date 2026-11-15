<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Validation;

use CajeerEngine\Forum\ForumException;
use CajeerEngine\Forum\Repository\ForumSearchQuery;

/** Validate all user-controlled search options before they reach the SQL builder. */
final class ForumSearchOptions
{
    public static function parse(array $input): array
    {
        $options=[];
        foreach (['board_id','author_id'] as $key) if (isset($input[$key])) $options[$key]=ForumValidator::id($input[$key]);
        foreach (['sort'=>['relevance','newest','oldest','active','replies'],'question'=>['all','questions','solved','unsolved','unanswered'],'discover'=>['','suggest','similar']] as $key=>$values) {
            $value=$input[$key]??$values[0];
            if (!in_array($value,$values,true)) throw new ForumException('Некорректное поле: '.$key);
            $options[$key]=$value;
        }
        $days=[];
        foreach (['from','to'] as $key) if (isset($input[$key])) {
            $value=$input[$key];
            if (!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$value)) throw new ForumException('Некорректная дата: '.$key);
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d')!==$value || $value<'0001-01-01' || $value>'9999-12-30') throw new ForumException('Некорректная дата: '.$key);
            $days[$key]=$value;
            // Inclusive end day, represented by exclusive next midnight. SQL timestamp columns use UTC.
            $options[$key]=($key==='to'?$date->modify('+1 day'):$date)->format('Y-m-d H:i:s');
        }
        if (isset($days['from'],$days['to']) && $days['from']>$days['to']) throw new ForumException('Начальная дата позже конечной.');
        return $options;
    }

    public static function validateTerm(string $term,string $mode,array $options,?string $tag): void
    {
        if (!in_array($mode,['threads','posts'],true)) throw new ForumException('Некорректный режим поиска.');
        $expr=ForumSearchQuery::expression($term);
        if ($term!=='' && (!$expr ? (bool)ForumSearchQuery::words($term) : !array_filter($expr,static fn($x)=>!$x['negative']))) throw new ForumException('Введите слова или фразу для поиска (до 16 условий).');
        $discover=$options['discover'];
        if ($discover!=='') {
            if ($mode!=='threads' || $term==='' || !$expr || array_filter($expr,static fn($x)=>$x['negative']||$x['phrase'])) throw new ForumException('Для подсказок и похожих тем введите обычные слова.');
        }
        if ($term==='' && ($mode==='posts' || ($tag===null && !isset($options['board_id']) && !isset($options['author_id']) && !isset($options['from']) && !isset($options['to']) && $options['question']==='all'))) throw new ForumException('Введите запрос или выберите фильтр обсуждений.');
    }
}
