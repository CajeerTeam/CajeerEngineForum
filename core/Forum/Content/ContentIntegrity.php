<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Content;

/** Offline content diagnostics. Does not require targets or files of an old draft to survive. */
final class ContentIntegrity
{
    private static function id(mixed $value): bool
    {return is_string($value)&&preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/D',$value)===1;}

    public static function draft(array $row): bool
    {
        try {
            $scope=$row['scope'];$target=$row['target_id'];
            if(!in_array($scope,['thread','reply','conversation','new_conversation'],true)||($scope==='new_conversation'?$target!==null:!self::id($target)))return false;
            $p=json_decode($row['payload'],false,32,JSON_THROW_ON_ERROR);
            if(!$p instanceof \stdClass)return false;
            if($row['result_json']!==null){
                $r=json_decode($row['result_json'],false,32,JSON_THROW_ON_ERROR);
                if(get_object_vars($p)!==[]||!$r instanceof \stdClass||!self::id($r->id??null))return false;
                if(($r->pending_review??false)===true)return in_array($scope,['thread','reply'],true)&&($r->submission_id??null)===$r->id;
                return match($scope){
                    'thread'=>true,
                    'reply'=>($r->thread_id??null)===$target&&is_int($r->page??null)&&$r->page>=1,
                    'conversation'=>($r->conversation_id??null)===$target&&is_int($r->message_sequence??null)&&$r->message_sequence>=1,
                    'new_conversation'=>self::id($r->message_id??null),
                };
            }
            if(!is_string($p->body??null)||!is_string($p->body_format??null)||!is_array($p->attachment_ids??null)||!array_is_list($p->attachment_ids)||count($p->attachment_ids)>10)return false;
            ForumContentRenderer::source($p->body,$p->body_format,true);
            foreach($p->attachment_ids as $id)if(!self::id($id))return false;
            if(count(array_unique($p->attachment_ids))!==count($p->attachment_ids))return false;
            if(in_array($scope,['thread','new_conversation'],true)&&!is_string($p->title??null))return false;
            if($scope==='thread'){
                if(!in_array($p->kind??null,['discussion','poll','question'],true))return false;
                if($p->kind==='poll'){
                    $poll=$p->poll??null;
                    if(!$poll instanceof \stdClass||!is_string($poll->question??null)||!is_array($poll->options??null)||count($poll->options)>20||!is_int($poll->max_choices??null)||$poll->max_choices<1||$poll->max_choices>20||!is_bool($poll->allow_change??null)||!in_array($poll->visibility??null,['always','voted','closed'],true)||!property_exists($poll,'closes_at')||($poll->closes_at!==null&&!is_int($poll->closes_at)))return false;
                    foreach($poll->options as $option)if(!is_string($option))return false;
                }
            }
            if($scope==='new_conversation'){
                if(!in_array($p->kind??null,['direct','group'],true)||!is_array($p->participant_ids??null)||count($p->participant_ids)>19)return false;
                foreach($p->participant_ids as $id)if(!self::id($id))return false;
            }
            return true;
        }catch(\JsonException|\CajeerEngine\Forum\ForumException){return false;}
    }

    /** Pending review payload and its file reservations must agree exactly. */
    public static function submission(array $row,array $held): bool
    {
        $draft=['scope'=>$row['thread_id']===null?'thread':'reply','target_id'=>$row['thread_id']??$row['board_id'],'payload'=>$row['payload'],'result_json'=>null];
        if(!self::draft($draft))return false;
        $p=json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR);
        $expected=$row['status']==='pending'?$p['attachment_ids']:[];
        sort($held);sort($expected);
        return $held===$expected;
    }

    /** The ledger may outlive its attachment (remote deletion tombstone). */
    public static function replica(array $row): bool
    {
        if(!self::id($row['attachment_id'])||!preg_match('/\A[a-f0-9]{64}\z/D',(string)$row['destination']))return false;
        if(!preg_match('~\Aforum-media/[a-zA-Z0-9_-]{1,80}/'.preg_quote($row['attachment_id'],'~').'/([a-f0-9]{64})\z~D',(string)$row['object_key'],$m))return false;
        return $row['sha256']===null||hash_equals((string)$row['sha256'],$m[1]);
    }
}
