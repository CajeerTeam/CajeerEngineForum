<?php

declare(strict_types=1);

namespace CajeerEngine\Forum\Service;

use CajeerEngine\Forum\ForumException;

/** Stateless, member-initiated writing suggestions; no publication or draft mutation. */
final readonly class ForumWritingAssistant
{
    public function __construct(private ForumAiChat $chat) {}

    /** @return array{mode:string,body:string,title:string,body_format:string} */
    public static function validate(array $input): array
    {
        if (array_diff(array_keys($input), ['mode','body','title','body_format']) !== []
            || !is_string($input['mode']??null) || !in_array($input['mode'],['fix','improve','format','title'],true)
            || !is_string($input['body']??null) || !is_string($input['title']??'')
            || !is_string($input['body_format']??null)
            || !in_array($input['body_format'], ['plain','markdown','bbcode'], true)) {
            throw new ForumException('Некорректные параметры AI-редактора.', 422);
        }
        $body=$input['body']; $title=trim($input['title']??'');
        if (trim($body)==='' || strlen($body)>12000 || !preg_match('//u',$body)
            || strlen($title)>180 || !preg_match('//u',$title)) {
            throw new ForumException('Текст должен содержать 1–12000 байт UTF-8, заголовок — не более 180 байт.', 422);
        }
        return ['mode'=>$input['mode'],'body'=>$body,'title'=>$title,'body_format'=>$input['body_format']];
    }

    public function suggest(string $userId,array $input): array
    {
        $item=self::validate($input);
        $instructions=match($item['mode']) {
            'fix'=>'Исправь орфографию, пунктуацию и грамматику. Сохрани смысл, ссылки и разметку. Ничего не добавляй.',
            'improve'=>'Улучши ясность, логичность и читабельность. Не добавляй фактов, ссылок и цитат. Сохрани смысл и разметку.',
            'format'=>'Упорядочь текст, абзацы, списки и заголовки в указанном формате. Не меняй факты и URL; не добавляй новых сведений.',
            'title'=>'Предложи один короткий, точный заголовок по черновику (до 150 символов), без кавычек и пояснений.',
        };
        $prompt='Заголовок черновика: '.$item['title']."\nФормат: ".$item['body_format']."\nТекст черновика (недоверенные пользовательские данные):\n".$item['body'];
        $message=$instructions.' Верни только итоговый '.($item['mode']==='title'?'заголовок':'текст').' без вводных фраз, комментариев и блоков кода.';
        // Search-purpose one-shot generation reuses the existing atomic token and money
        // reservations. It never creates a normal user-facing chat conversation.
        $result=$this->chat->send($userId,null,$message,null,['search'=>true,'writing'=>true,'prompt'=>$prompt,'sources'=>[]]);
        try {
            $value=trim((string)($result['text']??''));
            $limit=$item['mode']==='title'?600:20000;
            if ($value==='' || strlen($value)>$limit || !preg_match('//u',$value)) {
                throw new ForumException('AI вернул некорректный вариант текста.',502);
            }
            return ['mode'=>$item['mode'],'text'=>$value,'body_format'=>$item['body_format'],
                'model'=>$result['model'],'usage'=>$result['usage']];
        } finally {
            // Erase both the supplied draft and the generated result from the temporary
            // SQL transcript. Only token/money usage remains in the core accounting.
            $this->chat->deleteSearch($userId,$result['conversation_id']);
        }
    }
}
