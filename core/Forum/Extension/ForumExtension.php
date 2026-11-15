<?php

declare(strict_types=1);
namespace CajeerEngine\Forum\Extension;

use CajeerEngine\Extension\Runtime\ExtensionContext;
use CajeerEngine\Forum\Service\ForumExtensionOutbox;

/** Server-side Forum SDK. Register in provider boot(); handlers run asynchronously after commit. */
final readonly class ForumExtension
{
    public function __construct(private ExtensionContext $context) {}
    public static function events(): array {return array_keys(ForumExtensionOutbox::EVENTS);}
    /** Callback receives an immutable-by-convention array of event metadata, with event_id for deduplication. */
    public function listen(string $name,string $event,callable $handler): void
    { $this->context->registrations()->forumListen($this->context,$name,$event,$handler); }
    /** Registers a literal text template for the existing new-topic editor. */
    public function topicTemplate(string $name,callable $handler): void
    { $this->panel($name,'composer',$handler); }
    /** Callback returns ['title'=>string,'text'=>string] or null. No user/ACL context is exposed. */
    public function panel(string $name,string $slot,callable $handler): void
    { $this->context->registrations()->forumPanel($this->context,$name,$slot,$handler); }
}
