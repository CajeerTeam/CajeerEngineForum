<?php

declare(strict_types=1);
namespace CajeerEngine\ExtensionSdk;

/** Generated from api/openapi.yaml. */
final class Operations {
    public const ALL = array (
  'api_v1_ai_actions' => 
  array (
    'method' => 'GET',
    'path' => '/ai/actions',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_actions_approve' => 
  array (
    'method' => 'POST',
    'path' => '/ai/actions/{id}/approve',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_actions_reject' => 
  array (
    'method' => 'POST',
    'path' => '/ai/actions/{id}/reject',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_actions_show' => 
  array (
    'method' => 'GET',
    'path' => '/ai/actions/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_analytics_explain' => 
  array (
    'method' => 'POST',
    'path' => '/ai/analytics/explain',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_analytics_insights' => 
  array (
    'method' => 'GET',
    'path' => '/ai/analytics/insights',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'connection_id',
        'in' => 'query',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'from',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'to',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_automations_delete' => 
  array (
    'method' => 'DELETE',
    'path' => '/ai/automations/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_automations_index' => 
  array (
    'method' => 'GET',
    'path' => '/ai/automations',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_automations_run' => 
  array (
    'method' => 'POST',
    'path' => '/ai/automations/{id}/run',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_automations_runs' => 
  array (
    'method' => 'GET',
    'path' => '/ai/automations/{id}/runs',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_automations_show' => 
  array (
    'method' => 'GET',
    'path' => '/ai/automations/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_automations_store' => 
  array (
    'method' => 'POST',
    'path' => '/ai/automations',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_automations_update' => 
  array (
    'method' => 'PATCH',
    'path' => '/ai/automations/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_chat' => 
  array (
    'method' => 'POST',
    'path' => '/ai/chat',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_chat_stream' => 
  array (
    'method' => 'POST',
    'path' => '/ai/chat/stream',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_conversation_delete' => 
  array (
    'method' => 'DELETE',
    'path' => '/ai/conversations/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_conversation_rename' => 
  array (
    'method' => 'PATCH',
    'path' => '/ai/conversations/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_conversation_show' => 
  array (
    'method' => 'GET',
    'path' => '/ai/conversations/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_conversations' => 
  array (
    'method' => 'GET',
    'path' => '/ai/conversations',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_costs' => 
  array (
    'method' => 'GET',
    'path' => '/ai/costs',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'days',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_diagnostics_explain' => 
  array (
    'method' => 'POST',
    'path' => '/ai/diagnostics/explain',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_diagnostics_snapshot' => 
  array (
    'method' => 'GET',
    'path' => '/ai/diagnostics',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'window_minutes',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_editor_apply' => 
  array (
    'method' => 'POST',
    'path' => '/ai/editor/proposals/{id}/apply',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_editor_propose' => 
  array (
    'method' => 'POST',
    'path' => '/ai/editor/proposals',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_migration_explain' => 
  array (
    'method' => 'POST',
    'path' => '/ai/migration/projects/{id}/explain',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_migration_remediation_apply' => 
  array (
    'method' => 'POST',
    'path' => '/ai/migration/projects/{id}/remediation/{proposal}/apply',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'proposal',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_migration_remediation_options' => 
  array (
    'method' => 'GET',
    'path' => '/ai/migration/projects/{id}/remediation',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'offset',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_migration_remediation_prepare' => 
  array (
    'method' => 'POST',
    'path' => '/ai/migration/projects/{id}/remediation',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_migration_remediation_reject' => 
  array (
    'method' => 'POST',
    'path' => '/ai/migration/projects/{id}/remediation/{proposal}/reject',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'proposal',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_migration_remediation_rollback' => 
  array (
    'method' => 'POST',
    'path' => '/ai/migration/projects/{id}/remediation/{proposal}/rollback',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'proposal',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_migration_report' => 
  array (
    'method' => 'GET',
    'path' => '/ai/migration/projects/{id}/report',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_seo_audit' => 
  array (
    'method' => 'GET',
    'path' => '/ai/seo/audit',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'query',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'entry_id',
        'in' => 'query',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_seo_batch' => 
  array (
    'method' => 'GET',
    'path' => '/ai/seo/batch',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'query',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'limit',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_seo_insights' => 
  array (
    'method' => 'GET',
    'path' => '/ai/seo/insights',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'connection_id',
        'in' => 'query',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'from',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'to',
        'in' => 'query',
        'required' => false,
      ),
      3 => 
      array (
        'name' => 'limit',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_seo_insights_propose' => 
  array (
    'method' => 'POST',
    'path' => '/ai/seo/insights/proposals',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_seo_propose' => 
  array (
    'method' => 'POST',
    'path' => '/ai/seo/proposals',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_settings' => 
  array (
    'method' => 'PUT',
    'path' => '/ai/settings',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_status' => 
  array (
    'method' => 'GET',
    'path' => '/ai/status',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_tools' => 
  array (
    'method' => 'GET',
    'path' => '/ai/tools',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_translation_apply' => 
  array (
    'method' => 'POST',
    'path' => '/ai/translations/proposals/{id}/apply',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_translation_batch_create' => 
  array (
    'method' => 'POST',
    'path' => '/ai/translations/batches',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_translation_batch_show' => 
  array (
    'method' => 'GET',
    'path' => '/ai/translations/batches/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_translation_glossary' => 
  array (
    'method' => 'GET',
    'path' => '/ai/translations/glossary',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'query',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'source_locale',
        'in' => 'query',
        'required' => true,
      ),
      2 => 
      array (
        'name' => 'target_locale',
        'in' => 'query',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_translation_glossary_delete' => 
  array (
    'method' => 'DELETE',
    'path' => '/ai/translations/glossary/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_translation_glossary_save' => 
  array (
    'method' => 'POST',
    'path' => '/ai/translations/glossary',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_ai_translation_overview' => 
  array (
    'method' => 'GET',
    'path' => '/ai/translations/overview',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'query',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'entry_id',
        'in' => 'query',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_translation_proposal_show' => 
  array (
    'method' => 'GET',
    'path' => '/ai/translations/proposals/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_ai_translation_propose' => 
  array (
    'method' => 'POST',
    'path' => '/ai/translations/proposals',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_forum_acp_ai_configure' => 
  array (
    'method' => 'PUT',
    'path' => '/forum/acp/ai',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_forum_acp_ai_status' => 
  array (
    'method' => 'GET',
    'path' => '/forum/acp/ai',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'api_v1_forum_acp_support_action' => 
  array (
    'method' => 'POST',
    'path' => '/forum/acp/support/tickets/{id}/action',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_forum_acp_support_faqs' => 
  array (
    'method' => 'GET',
    'path' => '/forum/acp/support/faqs',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'api_v1_forum_acp_support_faqs_create' => 
  array (
    'method' => 'POST',
    'path' => '/forum/acp/support/faqs',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_forum_acp_support_faqs_delete' => 
  array (
    'method' => 'DELETE',
    'path' => '/forum/acp/support/faqs/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_forum_acp_support_faqs_update' => 
  array (
    'method' => 'PUT',
    'path' => '/forum/acp/support/faqs/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_forum_acp_support_queue' => 
  array (
    'method' => 'GET',
    'path' => '/forum/acp/support/tickets',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'api_v1_forum_acp_support_reply' => 
  array (
    'method' => 'POST',
    'path' => '/forum/acp/support/tickets/{id}/reply',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_forum_ai_chat' => 
  array (
    'method' => 'POST',
    'path' => '/forum/ai/chat',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_forum_ai_chat_stream' => 
  array (
    'method' => 'POST',
    'path' => '/forum/ai/chat/stream',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_forum_ai_conversation_delete' => 
  array (
    'method' => 'DELETE',
    'path' => '/forum/ai/conversations/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_forum_ai_conversation_rename' => 
  array (
    'method' => 'PATCH',
    'path' => '/forum/ai/conversations/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_forum_ai_conversation_show' => 
  array (
    'method' => 'GET',
    'path' => '/forum/ai/conversations/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_forum_ai_conversations' => 
  array (
    'method' => 'GET',
    'path' => '/forum/ai/conversations',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'api_v1_forum_ai_search_answer' => 
  array (
    'method' => 'POST',
    'path' => '/forum/ai/search',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_forum_ai_search_sources' => 
  array (
    'method' => 'GET',
    'path' => '/forum/ai/search',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'q',
        'in' => 'query',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_forum_ai_search_stream' => 
  array (
    'method' => 'POST',
    'path' => '/forum/ai/search/stream',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_forum_ai_status' => 
  array (
    'method' => 'GET',
    'path' => '/forum/ai/status',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'api_v1_forum_ai_writing_suggest' => 
  array (
    'method' => 'POST',
    'path' => '/forum/ai/writing/suggest',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_forum_moderation_ai_assess' => 
  array (
    'method' => 'POST',
    'path' => '/forum/moderation/cases/{kind}/{id}/ai',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'kind',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_forum_moderation_ai_read' => 
  array (
    'method' => 'GET',
    'path' => '/forum/moderation/cases/{kind}/{id}/ai',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'kind',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_forum_support_create' => 
  array (
    'method' => 'POST',
    'path' => '/forum/support/tickets',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_forum_support_faqs' => 
  array (
    'method' => 'GET',
    'path' => '/forum/support/faqs',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'api_v1_forum_support_handoff' => 
  array (
    'method' => 'POST',
    'path' => '/forum/support/tickets/{id}/handoff',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_forum_support_mine' => 
  array (
    'method' => 'GET',
    'path' => '/forum/support/tickets',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'api_v1_forum_support_reply' => 
  array (
    'method' => 'POST',
    'path' => '/forum/support/tickets/{id}/reply',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_forum_support_show' => 
  array (
    'method' => 'GET',
    'path' => '/forum/support/tickets/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_forum_thread_ai_conversation' => 
  array (
    'method' => 'GET',
    'path' => '/forum/threads/{id}/ai/conversations/{conversation_id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'conversation_id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_forum_thread_ai_delete' => 
  array (
    'method' => 'DELETE',
    'path' => '/forum/threads/{id}/ai/conversations/{conversation_id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'conversation_id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_forum_thread_ai_overview' => 
  array (
    'method' => 'GET',
    'path' => '/forum/threads/{id}/ai',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_forum_thread_ai_send' => 
  array (
    'method' => 'POST',
    'path' => '/forum/threads/{id}/ai',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_forum_thread_ai_stream' => 
  array (
    'method' => 'POST',
    'path' => '/forum/threads/{id}/ai/stream',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_identity_admin_link' => 
  array (
    'method' => 'POST',
    'path' => '/identity/admin/users/{id}/link',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_identity_admin_revoke_all' => 
  array (
    'method' => 'POST',
    'path' => '/identity/admin/users/{id}/sessions/revoke-all',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_identity_admin_revoke_session' => 
  array (
    'method' => 'DELETE',
    'path' => '/identity/admin/users/{id}/sessions/{session}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'session',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_identity_admin_sessions' => 
  array (
    'method' => 'GET',
    'path' => '/identity/admin/users/{id}/sessions',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_identity_admin_unlink' => 
  array (
    'method' => 'DELETE',
    'path' => '/identity/admin/users/{id}/link',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_identity_admin_users' => 
  array (
    'method' => 'GET',
    'path' => '/identity/admin/users',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'q',
        'in' => 'query',
        'required' => false,
      ),
      1 => 
      array (
        'name' => 'linked',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'limit',
        'in' => 'query',
        'required' => false,
      ),
      3 => 
      array (
        'name' => 'offset',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_knowledge_categories' => 
  array (
    'method' => 'GET',
    'path' => '/knowledge/categories',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'api_v1_knowledge_categories_create' => 
  array (
    'method' => 'POST',
    'path' => '/knowledge/categories',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_knowledge_categories_delete' => 
  array (
    'method' => 'DELETE',
    'path' => '/knowledge/categories/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_knowledge_categories_update' => 
  array (
    'method' => 'PUT',
    'path' => '/knowledge/categories/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_knowledge_chat_ask' => 
  array (
    'method' => 'POST',
    'path' => '/knowledge/chat/ask',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_knowledge_chat_conversations' => 
  array (
    'method' => 'GET',
    'path' => '/knowledge/chat/conversations',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'api_v1_knowledge_chat_delete' => 
  array (
    'method' => 'DELETE',
    'path' => '/knowledge/chat/conversations/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_knowledge_chat_feedback' => 
  array (
    'method' => 'POST',
    'path' => '/knowledge/chat/messages/{id}/feedback',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_knowledge_chat_show' => 
  array (
    'method' => 'GET',
    'path' => '/knowledge/chat/conversations/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_knowledge_documents' => 
  array (
    'method' => 'GET',
    'path' => '/knowledge/documents',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'limit',
        'in' => 'query',
        'required' => false,
      ),
      1 => 
      array (
        'name' => 'offset',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'category_id',
        'in' => 'query',
        'required' => false,
      ),
      3 => 
      array (
        'name' => 'q',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_knowledge_documents_create' => 
  array (
    'method' => 'POST',
    'path' => '/knowledge/documents',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_knowledge_documents_delete' => 
  array (
    'method' => 'DELETE',
    'path' => '/knowledge/documents/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_knowledge_documents_show' => 
  array (
    'method' => 'GET',
    'path' => '/knowledge/documents/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_knowledge_documents_update' => 
  array (
    'method' => 'PUT',
    'path' => '/knowledge/documents/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_knowledge_import' => 
  array (
    'method' => 'POST',
    'path' => '/knowledge/import',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_knowledge_reindex' => 
  array (
    'method' => 'POST',
    'path' => '/knowledge/reindex',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_knowledge_search' => 
  array (
    'method' => 'GET',
    'path' => '/knowledge/search',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'q',
        'in' => 'query',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'visibility',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'locale',
        'in' => 'query',
        'required' => false,
      ),
      3 => 
      array (
        'name' => 'limit',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_knowledge_sync' => 
  array (
    'method' => 'POST',
    'path' => '/knowledge/sync-cms',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_public_support_config' => 
  array (
    'method' => 'GET',
    'path' => '/public/support/config',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'api_v1_public_support_handoff' => 
  array (
    'method' => 'POST',
    'path' => '/public/support/tickets/{id}/handoff',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_public_support_messages_create' => 
  array (
    'method' => 'POST',
    'path' => '/public/support/tickets/{id}/messages',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_public_support_tickets_create' => 
  array (
    'method' => 'POST',
    'path' => '/public/support/tickets',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'api_v1_public_support_tickets_show' => 
  array (
    'method' => 'GET',
    'path' => '/public/support/tickets/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_support_ai_draft' => 
  array (
    'method' => 'POST',
    'path' => '/support/tickets/{id}/ai-draft',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_support_messages_create' => 
  array (
    'method' => 'POST',
    'path' => '/support/tickets/{id}/messages',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'api_v1_support_tickets' => 
  array (
    'method' => 'GET',
    'path' => '/support/tickets',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'api_v1_support_tickets_show' => 
  array (
    'method' => 'GET',
    'path' => '/support/tickets/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'api_v1_support_tickets_update' => 
  array (
    'method' => 'PATCH',
    'path' => '/support/tickets/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'delete_AnalyticsEventJournal' => 
  array (
    'method' => 'DELETE',
    'path' => '/tracking/events/journal',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'delete_AnalyticsReportDelete' => 
  array (
    'method' => 'DELETE',
    'path' => '/analytics/reports/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'delete_AnalyticsServerGrant' => 
  array (
    'method' => 'DELETE',
    'path' => '/tracking/events/server/grants/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'delete_ApiTokensRevoke' => 
  array (
    'method' => 'DELETE',
    'path' => '/api-tokens/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'delete_AuthSession' => 
  array (
    'method' => 'DELETE',
    'path' => '/auth/sessions/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'delete_CmsNavigationMenu' => 
  array (
    'method' => 'DELETE',
    'path' => '/cms/navigation/{handle}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'handle',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'delete_CmsRedirect' => 
  array (
    'method' => 'DELETE',
    'path' => '/cms/redirects/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'delete_ContentDelete' => 
  array (
    'method' => 'DELETE',
    'path' => '/content/{type}/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'delete_ContentTypesDelete' => 
  array (
    'method' => 'DELETE',
    'path' => '/content-types/{handle}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'handle',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'delete_Forum_deleteAttachment' => 
  array (
    'method' => 'DELETE',
    'path' => '/forum/attachments/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'delete_Forum_deleteBoard' => 
  array (
    'method' => 'DELETE',
    'path' => '/forum/boards/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'delete_Forum_deleteCategory' => 
  array (
    'method' => 'DELETE',
    'path' => '/forum/categories/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'delete_Forum_deleteDraft' => 
  array (
    'method' => 'DELETE',
    'path' => '/forum/drafts/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'delete_Forum_deleteGroup' => 
  array (
    'method' => 'DELETE',
    'path' => '/forum/groups/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'delete_Forum_deleteProfileField' => 
  array (
    'method' => 'DELETE',
    'path' => '/forum/profile-fields/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'delete_Forum_deleteProfileImage' => 
  array (
    'method' => 'DELETE',
    'path' => '/forum/account/images/{kind}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'kind',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'delete_Forum_removeConversationParticipant' => 
  array (
    'method' => 'DELETE',
    'path' => '/forum/conversations/{id}/participants/{user_id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'user_id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'delete_IntegrationsDelete' => 
  array (
    'method' => 'DELETE',
    'path' => '/integrations/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'delete_MediaDelete' => 
  array (
    'method' => 'DELETE',
    'path' => '/media/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'delete_MigrationProjectDelete' => 
  array (
    'method' => 'DELETE',
    'path' => '/migration/projects/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'delete_UsersDisable' => 
  array (
    'method' => 'DELETE',
    'path' => '/users/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'delete_WebhooksDelete' => 
  array (
    'method' => 'DELETE',
    'path' => '/webhooks/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'extensionActionRead' => 
  array (
    'method' => 'GET',
    'path' => '/extensions/actions/{slug}/{action}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'slug',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'action',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'extensionActionWrite' => 
  array (
    'method' => 'POST',
    'path' => '/extensions/actions/{slug}/{action}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'slug',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'action',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_AdminBootstrap' => 
  array (
    'method' => 'GET',
    'path' => '/admin/bootstrap',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_AdminDashboard' => 
  array (
    'method' => 'GET',
    'path' => '/admin/dashboard',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_AnalyticsEventConfig' => 
  array (
    'method' => 'GET',
    'path' => '/tracking/events',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_AnalyticsEventJournal' => 
  array (
    'method' => 'GET',
    'path' => '/tracking/events/journal',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_AnalyticsReportData' => 
  array (
    'method' => 'GET',
    'path' => '/analytics/reports/{id}/data',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'from',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'to',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_AnalyticsReportIndex' => 
  array (
    'method' => 'GET',
    'path' => '/analytics/reports',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_AnalyticsServerStatus' => 
  array (
    'method' => 'GET',
    'path' => '/tracking/events/server/grants/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_ApiTokensIndex' => 
  array (
    'method' => 'GET',
    'path' => '/api-tokens',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_AuthAssuranceStatus' => 
  array (
    'method' => 'GET',
    'path' => '/auth/assurance',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_AuthMe' => 
  array (
    'method' => 'GET',
    'path' => '/auth/me',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_AuthOidcStatus' => 
  array (
    'method' => 'GET',
    'path' => '/auth/oidc/status',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_AuthPrivacyExport' => 
  array (
    'method' => 'GET',
    'path' => '/auth/privacy/export',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'section',
        'in' => 'query',
        'required' => false,
      ),
      1 => 
      array (
        'name' => 'offset',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_AuthSessions' => 
  array (
    'method' => 'GET',
    'path' => '/auth/sessions',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_CmsNavigation' => 
  array (
    'method' => 'GET',
    'path' => '/cms/navigation',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_CmsNavigationMenu' => 
  array (
    'method' => 'GET',
    'path' => '/cms/navigation/{handle}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'handle',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_CmsOverview' => 
  array (
    'method' => 'GET',
    'path' => '/cms',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_CmsRedirects' => 
  array (
    'method' => 'GET',
    'path' => '/cms/redirects',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_CmsRoutes' => 
  array (
    'method' => 'GET',
    'path' => '/cms/routes',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'limit',
        'in' => 'query',
        'required' => false,
      ),
      1 => 
      array (
        'name' => 'offset',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_CmsTheme' => 
  array (
    'method' => 'GET',
    'path' => '/cms/theme',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_ConsentPolicy' => 
  array (
    'method' => 'GET',
    'path' => '/privacy/consent',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_ContentIndex' => 
  array (
    'method' => 'GET',
    'path' => '/content/{type}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'status',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'locale',
        'in' => 'query',
        'required' => false,
      ),
      3 => 
      array (
        'name' => 'q',
        'in' => 'query',
        'required' => false,
      ),
      4 => 
      array (
        'name' => 'published_only',
        'in' => 'query',
        'required' => false,
      ),
      5 => 
      array (
        'name' => 'limit',
        'in' => 'query',
        'required' => false,
      ),
      6 => 
      array (
        'name' => 'offset',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_ContentLocalizations' => 
  array (
    'method' => 'GET',
    'path' => '/content/{type}/{id}/localizations',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_ContentRevisions' => 
  array (
    'method' => 'GET',
    'path' => '/content/{type}/{id}/revisions',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_ContentShow' => 
  array (
    'method' => 'GET',
    'path' => '/content/{type}/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_ContentTypesIndex' => 
  array (
    'method' => 'GET',
    'path' => '/content-types',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_ContentTypesSchema' => 
  array (
    'method' => 'GET',
    'path' => '/content-types/{handle}/schema',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'handle',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_ContentTypesShow' => 
  array (
    'method' => 'GET',
    'path' => '/content-types/{handle}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'handle',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_DatabaseMigrations' => 
  array (
    'method' => 'GET',
    'path' => '/database/migrations',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_DatabaseShow' => 
  array (
    'method' => 'GET',
    'path' => '/database',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_ExtensionRuntime' => 
  array (
    'method' => 'GET',
    'path' => '/extensions/runtime',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_ExtensionsDiagnostics' => 
  array (
    'method' => 'GET',
    'path' => '/extensions/diagnostics',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_ExtensionsEvents' => 
  array (
    'method' => 'GET',
    'path' => '/extensions/events',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'enabled',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_ExtensionsIndex' => 
  array (
    'method' => 'GET',
    'path' => '/extensions',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'query',
        'required' => false,
      ),
      1 => 
      array (
        'name' => 'enabled',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'installed',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_ExtensionsPermissions' => 
  array (
    'method' => 'GET',
    'path' => '/extensions/permissions',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'enabled',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_achievementRules' => 
  array (
    'method' => 'GET',
    'path' => '/forum/achievement-rules',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_acp' => 
  array (
    'method' => 'GET',
    'path' => '/forum/acp',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'view',
        'in' => 'query',
        'required' => false,
      ),
      1 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'q',
        'in' => 'query',
        'required' => false,
      ),
      3 => 
      array (
        'name' => 'status',
        'in' => 'query',
        'required' => false,
      ),
      4 => 
      array (
        'name' => 'board_id',
        'in' => 'query',
        'required' => false,
      ),
      5 => 
      array (
        'name' => 'thread_id',
        'in' => 'query',
        'required' => false,
      ),
      6 => 
      array (
        'name' => 'id',
        'in' => 'query',
        'required' => false,
      ),
      7 => 
      array (
        'name' => 'job',
        'in' => 'query',
        'required' => false,
      ),
      8 => 
      array (
        'name' => 'from',
        'in' => 'query',
        'required' => false,
      ),
      9 => 
      array (
        'name' => 'until',
        'in' => 'query',
        'required' => false,
      ),
      10 => 
      array (
        'name' => 'days',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_activity' => 
  array (
    'method' => 'GET',
    'path' => '/forum/activity',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
      1 => 
      array (
        'name' => 'scope',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_answers' => 
  array (
    'method' => 'GET',
    'path' => '/forum/threads/{id}/answers',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'sort',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_attachment' => 
  array (
    'method' => 'GET',
    'path' => '/forum/attachments/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_attachmentImage' => 
  array (
    'method' => 'GET',
    'path' => '/forum/attachments/{id}/image/{variant}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'variant',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_attachments' => 
  array (
    'method' => 'GET',
    'path' => '/forum/attachments',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_board' => 
  array (
    'method' => 'GET',
    'path' => '/forum/boards/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_boardPermissions' => 
  array (
    'method' => 'GET',
    'path' => '/forum/boards/{id}/permissions',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_categoryPermissions' => 
  array (
    'method' => 'GET',
    'path' => '/forum/categories/{id}/permissions',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_conversation' => 
  array (
    'method' => 'GET',
    'path' => '/forum/conversations/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_conversationReport' => 
  array (
    'method' => 'GET',
    'path' => '/forum/conversation-reports/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_conversationReports' => 
  array (
    'method' => 'GET',
    'path' => '/forum/conversation-reports',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
      1 => 
      array (
        'name' => 'status',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_conversationRevisions' => 
  array (
    'method' => 'GET',
    'path' => '/forum/conversations/{id}/messages/{message_id}/revisions',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'message_id',
        'in' => 'path',
        'required' => true,
      ),
      2 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_conversationSettings' => 
  array (
    'method' => 'GET',
    'path' => '/forum/conversation-settings',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_conversations' => 
  array (
    'method' => 'GET',
    'path' => '/forum/conversations',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
      1 => 
      array (
        'name' => 'archived',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'notifications',
        'in' => 'query',
        'required' => false,
      ),
      3 => 
      array (
        'name' => 'q',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_downloadAttachment' => 
  array (
    'method' => 'GET',
    'path' => '/forum/attachments/{id}/download',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_draft' => 
  array (
    'method' => 'GET',
    'path' => '/forum/drafts/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_drafts' => 
  array (
    'method' => 'GET',
    'path' => '/forum/drafts',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
      1 => 
      array (
        'name' => 'scope',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'target_id',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_groupMembership' => 
  array (
    'method' => 'GET',
    'path' => '/forum/members/{id}/groups',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_groups' => 
  array (
    'method' => 'GET',
    'path' => '/forum/groups',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_index' => 
  array (
    'method' => 'GET',
    'path' => '/forum',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_latest' => 
  array (
    'method' => 'GET',
    'path' => '/forum/latest',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
      1 => 
      array (
        'name' => 'cursor',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_leaderboard' => 
  array (
    'method' => 'GET',
    'path' => '/forum/leaderboard',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_mediaStatus' => 
  array (
    'method' => 'GET',
    'path' => '/forum/media/status',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_memberSanctions' => 
  array (
    'method' => 'GET',
    'path' => '/forum/members/{id}/sanctions',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_members' => 
  array (
    'method' => 'GET',
    'path' => '/forum/members',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'q',
        'in' => 'query',
        'required' => false,
      ),
      1 => 
      array (
        'name' => 'sort',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
      3 => 
      array (
        'name' => 'discovery',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_moderationDetail' => 
  array (
    'method' => 'GET',
    'path' => '/forum/moderation/cases/{kind}/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'kind',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      2 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_moderationQueue' => 
  array (
    'method' => 'GET',
    'path' => '/forum/moderation/queue',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
      1 => 
      array (
        'name' => 'kind',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'status',
        'in' => 'query',
        'required' => false,
      ),
      3 => 
      array (
        'name' => 'assigned',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_myReports' => 
  array (
    'method' => 'GET',
    'path' => '/forum/my-reports',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
      1 => 
      array (
        'name' => 'kind',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_mySanctions' => 
  array (
    'method' => 'GET',
    'path' => '/forum/sanctions',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_mySubmissions' => 
  array (
    'method' => 'GET',
    'path' => '/forum/submissions',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_notificationTarget' => 
  array (
    'method' => 'GET',
    'path' => '/forum/notifications/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_notifications' => 
  array (
    'method' => 'GET',
    'path' => '/forum/notifications',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
      1 => 
      array (
        'name' => 'count_only',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_ownProfile' => 
  array (
    'method' => 'GET',
    'path' => '/forum/account/profile',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_permissionInspector' => 
  array (
    'method' => 'GET',
    'path' => '/forum/permission-inspector',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'kind',
        'in' => 'query',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'query',
        'required' => true,
      ),
      2 => 
      array (
        'name' => 'user_id',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_postRevisions' => 
  array (
    'method' => 'GET',
    'path' => '/forum/posts/{id}/revisions',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_profile' => 
  array (
    'method' => 'GET',
    'path' => '/forum/members/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_profileFields' => 
  array (
    'method' => 'GET',
    'path' => '/forum/profile-fields',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_profileImage' => 
  array (
    'method' => 'GET',
    'path' => '/forum/members/{id}/images/{kind}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'kind',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_questions' => 
  array (
    'method' => 'GET',
    'path' => '/forum/questions',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
      1 => 
      array (
        'name' => 'status',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_recycleBin' => 
  array (
    'method' => 'GET',
    'path' => '/forum/recycle-bin',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
      1 => 
      array (
        'name' => 'kind',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_recycleDetail' => 
  array (
    'method' => 'GET',
    'path' => '/forum/recycle-bin/{kind}/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'kind',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      2 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_relations' => 
  array (
    'method' => 'GET',
    'path' => '/forum/member-relations',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
      1 => 
      array (
        'name' => 'kind',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_relationship' => 
  array (
    'method' => 'GET',
    'path' => '/forum/members/{id}/relationship',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_reports' => 
  array (
    'method' => 'GET',
    'path' => '/forum/reports',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_reviewDownload' => 
  array (
    'method' => 'GET',
    'path' => '/forum/moderation/submissions/{id}/files/{file_id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'file_id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_sanctionAppeals' => 
  array (
    'method' => 'GET',
    'path' => '/forum/moderation/appeals',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_search' => 
  array (
    'method' => 'GET',
    'path' => '/forum/search',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
      1 => 
      array (
        'name' => 'q',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'tag',
        'in' => 'query',
        'required' => false,
      ),
      3 => 
      array (
        'name' => 'mode',
        'in' => 'query',
        'required' => false,
      ),
      4 => 
      array (
        'name' => 'board_id',
        'in' => 'query',
        'required' => false,
      ),
      5 => 
      array (
        'name' => 'author_id',
        'in' => 'query',
        'required' => false,
      ),
      6 => 
      array (
        'name' => 'from',
        'in' => 'query',
        'required' => false,
      ),
      7 => 
      array (
        'name' => 'to',
        'in' => 'query',
        'required' => false,
      ),
      8 => 
      array (
        'name' => 'sort',
        'in' => 'query',
        'required' => false,
      ),
      9 => 
      array (
        'name' => 'question',
        'in' => 'query',
        'required' => false,
      ),
      10 => 
      array (
        'name' => 'discover',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_spamStats' => 
  array (
    'method' => 'GET',
    'path' => '/forum/antispam/status',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_thread' => 
  array (
    'method' => 'GET',
    'path' => '/forum/threads/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'first_unread',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_unread' => 
  array (
    'method' => 'GET',
    'path' => '/forum/unread',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_watchSettings' => 
  array (
    'method' => 'GET',
    'path' => '/forum/watch-settings',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Forum_watches' => 
  array (
    'method' => 'GET',
    'path' => '/forum/watches',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'page',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Health' => 
  array (
    'method' => 'GET',
    'path' => '/health',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_IdentityAccount' => 
  array (
    'method' => 'GET',
    'path' => '/identity/account',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_ImportExportDownload' => 
  array (
    'method' => 'GET',
    'path' => '/import-export/download/{file}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'file',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_ImportExportExports' => 
  array (
    'method' => 'GET',
    'path' => '/import-export/exports',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_ImportExportRequests' => 
  array (
    'method' => 'GET',
    'path' => '/import-export/requests',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_InstallerRequirements' => 
  array (
    'method' => 'GET',
    'path' => '/installer/requirements',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_IntegrationsIndex' => 
  array (
    'method' => 'GET',
    'path' => '/integrations',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_MediaDiagnostics' => 
  array (
    'method' => 'GET',
    'path' => '/media/diagnostics',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'verify',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_MediaDownload' => 
  array (
    'method' => 'GET',
    'path' => '/media/{id}/download',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'variant',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'version',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_MediaIndex' => 
  array (
    'method' => 'GET',
    'path' => '/media',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'q',
        'in' => 'query',
        'required' => false,
      ),
      1 => 
      array (
        'name' => 'folder',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'mime',
        'in' => 'query',
        'required' => false,
      ),
      3 => 
      array (
        'name' => 'limit',
        'in' => 'query',
        'required' => false,
      ),
      4 => 
      array (
        'name' => 'offset',
        'in' => 'query',
        'required' => false,
      ),
      5 => 
      array (
        'name' => 'visibility',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_MediaShow' => 
  array (
    'method' => 'GET',
    'path' => '/media/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_MediaSign' => 
  array (
    'method' => 'GET',
    'path' => '/media/{id}/sign',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'variant',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'version',
        'in' => 'query',
        'required' => false,
      ),
      3 => 
      array (
        'name' => 'ttl',
        'in' => 'query',
        'required' => false,
      ),
      4 => 
      array (
        'name' => 's3',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_MediaSignedDownload' => 
  array (
    'method' => 'GET',
    'path' => '/media/{id}/signed-download',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'variant',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'version',
        'in' => 'query',
        'required' => false,
      ),
      3 => 
      array (
        'name' => 'expires',
        'in' => 'query',
        'required' => true,
      ),
      4 => 
      array (
        'name' => 'signature',
        'in' => 'query',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_MigrationCatalog' => 
  array (
    'method' => 'GET',
    'path' => '/migration/catalog',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_MigrationProjectIndex' => 
  array (
    'method' => 'GET',
    'path' => '/migration/projects',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_MigrationProjectShow' => 
  array (
    'method' => 'GET',
    'path' => '/migration/projects/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_PublicConsent' => 
  array (
    'method' => 'GET',
    'path' => '/public/consent',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_PublicPreview' => 
  array (
    'method' => 'GET',
    'path' => '/public/preview/{type}/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      2 => 
      array (
        'name' => 'format',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_PublicSearch' => 
  array (
    'method' => 'GET',
    'path' => '/public/search',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'q',
        'in' => 'query',
        'required' => false,
      ),
      1 => 
      array (
        'name' => 'index',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'limit',
        'in' => 'query',
        'required' => false,
      ),
      3 => 
      array (
        'name' => 'offset',
        'in' => 'query',
        'required' => false,
      ),
      4 => 
      array (
        'name' => 'type',
        'in' => 'query',
        'required' => false,
      ),
      5 => 
      array (
        'name' => 'locale',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_PublicSitemap' => 
  array (
    'method' => 'GET',
    'path' => '/public/sitemap',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_QueueDiagnostics' => 
  array (
    'method' => 'GET',
    'path' => '/queue',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_QueueFailed' => 
  array (
    'method' => 'GET',
    'path' => '/queue/failed',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'queue',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_RcChecks' => 
  array (
    'method' => 'GET',
    'path' => '/rc/checks',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_RcReadiness' => 
  array (
    'method' => 'GET',
    'path' => '/rc/readiness',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_RolesIndex' => 
  array (
    'method' => 'GET',
    'path' => '/roles',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_RolesShow' => 
  array (
    'method' => 'GET',
    'path' => '/roles/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_Runtime' => 
  array (
    'method' => 'GET',
    'path' => '/runtime',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_SchedulerTasks' => 
  array (
    'method' => 'GET',
    'path' => '/scheduler/tasks',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_SearchDiagnostics' => 
  array (
    'method' => 'GET',
    'path' => '/search/diagnostics',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_SearchIndex' => 
  array (
    'method' => 'GET',
    'path' => '/search',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'q',
        'in' => 'query',
        'required' => false,
      ),
      1 => 
      array (
        'name' => 'index',
        'in' => 'query',
        'required' => false,
      ),
      2 => 
      array (
        'name' => 'limit',
        'in' => 'query',
        'required' => false,
      ),
      3 => 
      array (
        'name' => 'offset',
        'in' => 'query',
        'required' => false,
      ),
      4 => 
      array (
        'name' => 'type',
        'in' => 'query',
        'required' => false,
      ),
      5 => 
      array (
        'name' => 'locale',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_SecurityShow' => 
  array (
    'method' => 'GET',
    'path' => '/security',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_StableStatus' => 
  array (
    'method' => 'GET',
    'path' => '/stable',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_System' => 
  array (
    'method' => 'GET',
    'path' => '/system',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_SystemOperations' => 
  array (
    'method' => 'GET',
    'path' => '/system/operations',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_TrackingConfig' => 
  array (
    'method' => 'GET',
    'path' => '/tracking/counters',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_UpdatesDiagnostics' => 
  array (
    'method' => 'GET',
    'path' => '/updates',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_UpdatesHistory' => 
  array (
    'method' => 'GET',
    'path' => '/updates/history',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'before',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_UpdatesMaintenance' => 
  array (
    'method' => 'GET',
    'path' => '/updates/maintenance',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_UpdatesNotifications' => 
  array (
    'method' => 'GET',
    'path' => '/updates/notifications',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_Usage' => 
  array (
    'method' => 'GET',
    'path' => '/media/{id}/usage',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_UsersIndex' => 
  array (
    'method' => 'GET',
    'path' => '/users',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'get_UsersShow' => 
  array (
    'method' => 'GET',
    'path' => '/users/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_WebhookDeliveriesIndex' => 
  array (
    'method' => 'GET',
    'path' => '/webhook-deliveries',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'webhook_id',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'get_WebhooksIndex' => 
  array (
    'method' => 'GET',
    'path' => '/webhooks',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'patch_CmsRedirect' => 
  array (
    'method' => 'PATCH',
    'path' => '/cms/redirects/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'patch_CmsTheme' => 
  array (
    'method' => 'PATCH',
    'path' => '/cms/theme',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'patch_ContentPatch' => 
  array (
    'method' => 'PATCH',
    'path' => '/content/{type}/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'patch_ContentTypesPatch' => 
  array (
    'method' => 'PATCH',
    'path' => '/content-types/{handle}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'handle',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'patch_ExtensionsConfig' => 
  array (
    'method' => 'PATCH',
    'path' => '/extensions/config',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'patch_Forum_acp_members_id' => 
  array (
    'method' => 'PATCH',
    'path' => '/forum/acp/members/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'patch_Forum_conversationFlags' => 
  array (
    'method' => 'PATCH',
    'path' => '/forum/conversations/{id}/settings',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'patch_Forum_edit' => 
  array (
    'method' => 'PATCH',
    'path' => '/forum/posts/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'patch_Forum_editConversationMessage' => 
  array (
    'method' => 'PATCH',
    'path' => '/forum/conversations/{id}/messages/{message_id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'message_id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'patch_Forum_pollControl' => 
  array (
    'method' => 'PATCH',
    'path' => '/forum/threads/{id}/poll',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'patch_Forum_updateBoard' => 
  array (
    'method' => 'PATCH',
    'path' => '/forum/boards/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'patch_Forum_updateCategory' => 
  array (
    'method' => 'PATCH',
    'path' => '/forum/categories/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'patch_Forum_updateGroup' => 
  array (
    'method' => 'PATCH',
    'path' => '/forum/groups/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'patch_Forum_updatePreferences' => 
  array (
    'method' => 'PATCH',
    'path' => '/forum/account/preferences',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'patch_Forum_updateProfile' => 
  array (
    'method' => 'PATCH',
    'path' => '/forum/account/profile',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'patch_IntegrationsUpdate' => 
  array (
    'method' => 'PATCH',
    'path' => '/integrations/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'patch_Update' => 
  array (
    'method' => 'PATCH',
    'path' => '/media/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'patch_UsersPatch' => 
  array (
    'method' => 'PATCH',
    'path' => '/users/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'patch_WebhooksPatch' => 
  array (
    'method' => 'PATCH',
    'path' => '/webhooks/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'post_AnalyticsEventDiagnostic' => 
  array (
    'method' => 'POST',
    'path' => '/public/analytics/events',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_AnalyticsReportCreate' => 
  array (
    'method' => 'POST',
    'path' => '/analytics/reports',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_AnalyticsReportSync' => 
  array (
    'method' => 'POST',
    'path' => '/analytics/reports/{id}/sync',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_AnalyticsServerEvent' => 
  array (
    'method' => 'POST',
    'path' => '/tracking/events/server',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_AnalyticsServerGrant' => 
  array (
    'method' => 'POST',
    'path' => '/tracking/events/server/grants',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_AnalyticsWebmasterAction' => 
  array (
    'method' => 'POST',
    'path' => '/analytics/reports/{id}/webmaster',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_ApiTokensStore' => 
  array (
    'method' => 'POST',
    'path' => '/api-tokens',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_ApiV1AuthSessionsRevokeOthers' => 
  array (
    'method' => 'POST',
    'path' => '/auth/sessions/revoke-others',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_ApiV1AuthSessionsRotate' => 
  array (
    'method' => 'POST',
    'path' => '/auth/sessions/rotate',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_Auth2faConfirm' => 
  array (
    'method' => 'POST',
    'path' => '/auth/2fa/confirm',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_Auth2faDisable' => 
  array (
    'method' => 'POST',
    'path' => '/auth/2fa/disable',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_Auth2faEnable' => 
  array (
    'method' => 'POST',
    'path' => '/auth/2fa/enable',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_AuthAssuranceConfirm' => 
  array (
    'method' => 'POST',
    'path' => '/auth/assurance/confirm',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_AuthEmailResend' => 
  array (
    'method' => 'POST',
    'path' => '/auth/email/resend',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_AuthEmailVerify' => 
  array (
    'method' => 'POST',
    'path' => '/auth/email/verify',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_AuthLogin' => 
  array (
    'method' => 'POST',
    'path' => '/auth/login',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'X-Cajeer-Browser',
        'in' => 'header',
        'required' => false,
      ),
      1 => 
      array (
        'name' => 'X-Cajeer-Browser',
        'in' => 'header',
        'required' => false,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_AuthLogout' => 
  array (
    'method' => 'POST',
    'path' => '/auth/logout',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_AuthOidcGlobalLogout' => 
  array (
    'method' => 'POST',
    'path' => '/auth/oidc/logout',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_AuthPasswordForgot' => 
  array (
    'method' => 'POST',
    'path' => '/auth/password/forgot',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_AuthPasswordReset' => 
  array (
    'method' => 'POST',
    'path' => '/auth/password/reset',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_AuthPrivacyClearProfile' => 
  array (
    'method' => 'POST',
    'path' => '/auth/privacy/clear-profile',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_AuthPrivacyErase' => 
  array (
    'method' => 'POST',
    'path' => '/auth/privacy/erase',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_AuthSessionsRevokeAll' => 
  array (
    'method' => 'POST',
    'path' => '/auth/sessions/revoke-all',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_CmsBootstrap' => 
  array (
    'method' => 'POST',
    'path' => '/cms/bootstrap',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_CmsPreviewToken' => 
  array (
    'method' => 'POST',
    'path' => '/cms/preview-token',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_CmsRedirects' => 
  array (
    'method' => 'POST',
    'path' => '/cms/redirects',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_ContentArchive' => 
  array (
    'method' => 'POST',
    'path' => '/content/{type}/{id}/archive',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'post_ContentLocalizationsStore' => 
  array (
    'method' => 'POST',
    'path' => '/content/{type}/{id}/localizations',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'post_ContentPublish' => 
  array (
    'method' => 'POST',
    'path' => '/content/{type}/{id}/publish',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'post_ContentRevisionsRestore' => 
  array (
    'method' => 'POST',
    'path' => '/content/{type}/{id}/revisions/{revision}/restore',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      2 => 
      array (
        'name' => 'revision',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'post_ContentRevisionsRestoreShort' => 
  array (
    'method' => 'POST',
    'path' => '/content/{type}/{id}/revisions/{revision}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      2 => 
      array (
        'name' => 'revision',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'post_ContentStore' => 
  array (
    'method' => 'POST',
    'path' => '/content/{type}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'post_ContentTypesStore' => 
  array (
    'method' => 'POST',
    'path' => '/content-types',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_ContentUnpublish' => 
  array (
    'method' => 'POST',
    'path' => '/content/{type}/{id}/unpublish',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'post_EventsDispatch' => 
  array (
    'method' => 'POST',
    'path' => '/events/dispatch',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_ExtensionAssetsPublish' => 
  array (
    'method' => 'POST',
    'path' => '/extensions/assets/publish',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_ExtensionMigrate' => 
  array (
    'method' => 'POST',
    'path' => '/extensions/migrate',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_ExtensionsDisable' => 
  array (
    'method' => 'POST',
    'path' => '/extensions/disable',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_ExtensionsEnable' => 
  array (
    'method' => 'POST',
    'path' => '/extensions/enable',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_ExtensionsEventsDispatch' => 
  array (
    'method' => 'POST',
    'path' => '/extensions/events/dispatch',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_ExtensionsInstall' => 
  array (
    'method' => 'POST',
    'path' => '/extensions/install',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_ExtensionsUninstall' => 
  array (
    'method' => 'POST',
    'path' => '/extensions/uninstall',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_ExtensionsValidate' => 
  array (
    'method' => 'POST',
    'path' => '/extensions/validate',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'target',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'post_Forum_Native_Analytics_PageView' => 
  array (
    'method' => 'POST',
    'path' => '/forum/analytics/page-view',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_acp_maintenance' => 
  array (
    'method' => 'POST',
    'path' => '/forum/acp/maintenance',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_appealSanction' => 
  array (
    'method' => 'POST',
    'path' => '/forum/sanctions/{id}/appeal',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_bulkThreads' => 
  array (
    'method' => 'POST',
    'path' => '/forum/thread-actions/bulk',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_cancelSubmission' => 
  array (
    'method' => 'POST',
    'path' => '/forum/submissions/{id}/cancel',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_category' => 
  array (
    'method' => 'POST',
    'path' => '/forum/categories',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_createBoard' => 
  array (
    'method' => 'POST',
    'path' => '/forum/categories/{id}/boards',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_createConversation' => 
  array (
    'method' => 'POST',
    'path' => '/forum/conversations',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_createGroup' => 
  array (
    'method' => 'POST',
    'path' => '/forum/groups',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_createProfileField' => 
  array (
    'method' => 'POST',
    'path' => '/forum/profile-fields',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_createThread' => 
  array (
    'method' => 'POST',
    'path' => '/forum/boards/{id}/threads',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_decideSanctionAppeal' => 
  array (
    'method' => 'POST',
    'path' => '/forum/sanctions/{id}/appeal/decision',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_inviteConversation' => 
  array (
    'method' => 'POST',
    'path' => '/forum/conversations/{id}/invite',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_issueSanction' => 
  array (
    'method' => 'POST',
    'path' => '/forum/members/{id}/sanctions',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_leaveConversation' => 
  array (
    'method' => 'POST',
    'path' => '/forum/conversations/{id}/leave',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'post_Forum_markNotifications' => 
  array (
    'method' => 'POST',
    'path' => '/forum/notifications/read',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_markRead' => 
  array (
    'method' => 'POST',
    'path' => '/forum/read-state',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_mergeThreads' => 
  array (
    'method' => 'POST',
    'path' => '/forum/threads/{id}/merge',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_moderate' => 
  array (
    'method' => 'POST',
    'path' => '/forum/threads/{id}/moderate',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_moderateProfile' => 
  array (
    'method' => 'POST',
    'path' => '/forum/members/{id}/moderate-profile',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_moderationAction' => 
  array (
    'method' => 'POST',
    'path' => '/forum/moderation/cases/{kind}/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'kind',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_moveBoard' => 
  array (
    'method' => 'POST',
    'path' => '/forum/boards/{id}/move',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_previewContent' => 
  array (
    'method' => 'POST',
    'path' => '/forum/content/preview',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_publishDraft' => 
  array (
    'method' => 'POST',
    'path' => '/forum/drafts/{id}/publish',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_readConversation' => 
  array (
    'method' => 'POST',
    'path' => '/forum/conversations/{id}/read',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_recycleChange' => 
  array (
    'method' => 'POST',
    'path' => '/forum/recycle-bin/{kind}/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'kind',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_register' => 
  array (
    'method' => 'POST',
    'path' => '/forum/register',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_reorderNodes' => 
  array (
    'method' => 'POST',
    'path' => '/forum/nodes/reorder',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_reply' => 
  array (
    'method' => 'POST',
    'path' => '/forum/threads/{id}/posts',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_report' => 
  array (
    'method' => 'POST',
    'path' => '/forum/posts/{id}/report',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_reportConversationMessage' => 
  array (
    'method' => 'POST',
    'path' => '/forum/conversations/{id}/messages/{message_id}/report',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'message_id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_resolve' => 
  array (
    'method' => 'POST',
    'path' => '/forum/reports/{id}/resolve',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'post_Forum_restoreAttachmentOriginal' => 
  array (
    'method' => 'POST',
    'path' => '/forum/attachments/{id}/restore-original',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'post_Forum_restoreConversationRevision' => 
  array (
    'method' => 'POST',
    'path' => '/forum/conversations/{id}/messages/{message_id}/revisions/restore',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'message_id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_restorePostRevision' => 
  array (
    'method' => 'POST',
    'path' => '/forum/posts/{id}/revisions/restore',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_retryAttachmentImage' => 
  array (
    'method' => 'POST',
    'path' => '/forum/attachments/{id}/image/retry',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'post_Forum_revokeSanction' => 
  array (
    'method' => 'POST',
    'path' => '/forum/sanctions/{id}/revoke',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_sendConversation' => 
  array (
    'method' => 'POST',
    'path' => '/forum/conversations/{id}/messages',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_transferPosts' => 
  array (
    'method' => 'POST',
    'path' => '/forum/threads/{id}/transfer',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_unsubscribeEmail' => 
  array (
    'method' => 'POST',
    'path' => '/forum/unsubscribe',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_Forum_uploadAttachment' => 
  array (
    'method' => 'POST',
    'path' => '/forum/attachments',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_IdentityLinkStart' => 
  array (
    'method' => 'POST',
    'path' => '/identity/link/start',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_IdentityStepUpStart' => 
  array (
    'method' => 'POST',
    'path' => '/identity/step-up/start',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_IdentityUnlink' => 
  array (
    'method' => 'POST',
    'path' => '/identity/unlink',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_ImportExportExport' => 
  array (
    'method' => 'POST',
    'path' => '/import-export/export',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_ImportExportImport' => 
  array (
    'method' => 'POST',
    'path' => '/import-export/import',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_ImportExportUpload' => 
  array (
    'method' => 'POST',
    'path' => '/import-export/upload',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_Import_ExportDiff' => 
  array (
    'method' => 'POST',
    'path' => '/import-export/diff',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_InstallerRun' => 
  array (
    'method' => 'POST',
    'path' => '/installer/run',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_IntegrationsSend' => 
  array (
    'method' => 'POST',
    'path' => '/integrations/{id}/send',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_IntegrationsStore' => 
  array (
    'method' => 'POST',
    'path' => '/integrations',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_IntegrationsTest' => 
  array (
    'method' => 'POST',
    'path' => '/integrations/{id}/test',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_MediaStore' => 
  array (
    'method' => 'POST',
    'path' => '/media',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_MigrationProjectApprove' => 
  array (
    'method' => 'POST',
    'path' => '/migration/projects/{id}/approve',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_MigrationProjectCreate' => 
  array (
    'method' => 'POST',
    'path' => '/migration/projects',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_MigrationProjectPlan' => 
  array (
    'method' => 'POST',
    'path' => '/migration/projects/{id}/plan',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_MigrationProjectScan' => 
  array (
    'method' => 'POST',
    'path' => '/migration/projects/{id}/scan',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_MigrationProjectUpload' => 
  array (
    'method' => 'POST',
    'path' => '/migration/projects/{id}/wxr',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_QueueFailedRetry' => 
  array (
    'method' => 'POST',
    'path' => '/queue/failed/{id}/retry',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'queue',
        'in' => 'query',
        'required' => false,
      ),
    ),
    'bodyRequired' => false,
  ),
  'post_QueueJobsStore' => 
  array (
    'method' => 'POST',
    'path' => '/queue/jobs',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_QueueWork' => 
  array (
    'method' => 'POST',
    'path' => '/queue/work',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_RcRun' => 
  array (
    'method' => 'POST',
    'path' => '/rc/run',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_Replace' => 
  array (
    'method' => 'POST',
    'path' => '/media/{id}/replace',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'post_RolesStore' => 
  array (
    'method' => 'POST',
    'path' => '/roles',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_SchedulerRun' => 
  array (
    'method' => 'POST',
    'path' => '/scheduler/run',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_SearchReindex' => 
  array (
    'method' => 'POST',
    'path' => '/search/reindex',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_StableBackup' => 
  array (
    'method' => 'POST',
    'path' => '/stable/backup',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_StableReleaseLock' => 
  array (
    'method' => 'POST',
    'path' => '/stable/release-lock',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_StableSmokeTest' => 
  array (
    'method' => 'POST',
    'path' => '/stable/smoke-test',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_StableSupportBundle' => 
  array (
    'method' => 'POST',
    'path' => '/stable/support-bundle',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_UpdatesApply' => 
  array (
    'method' => 'POST',
    'path' => '/updates/apply',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_UpdatesBackup' => 
  array (
    'method' => 'POST',
    'path' => '/updates/backup',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_UpdatesCheck' => 
  array (
    'method' => 'POST',
    'path' => '/updates/check',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_UpdatesDownload' => 
  array (
    'method' => 'POST',
    'path' => '/updates/download',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_UpdatesMaintenanceOff' => 
  array (
    'method' => 'POST',
    'path' => '/updates/maintenance/off',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_UpdatesMaintenanceOn' => 
  array (
    'method' => 'POST',
    'path' => '/updates/maintenance/on',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_UpdatesNotificationsRead' => 
  array (
    'method' => 'POST',
    'path' => '/updates/notifications/read',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_UpdatesPlan' => 
  array (
    'method' => 'POST',
    'path' => '/updates/plan',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_UpdatesPrepare' => 
  array (
    'method' => 'POST',
    'path' => '/updates/prepare',
    'parameters' => 
    array (
    ),
    'bodyRequired' => false,
  ),
  'post_UpdatesRollback' => 
  array (
    'method' => 'POST',
    'path' => '/updates/rollback',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_UsersStore' => 
  array (
    'method' => 'POST',
    'path' => '/users',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_Variants' => 
  array (
    'method' => 'POST',
    'path' => '/media/{id}/variants',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'post_WebhooksStore' => 
  array (
    'method' => 'POST',
    'path' => '/webhooks',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'post_WebhooksTest' => 
  array (
    'method' => 'POST',
    'path' => '/webhooks/{id}/test',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'put_AnalyticsEventConfig' => 
  array (
    'method' => 'PUT',
    'path' => '/tracking/events',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'put_AnalyticsReportUpdate' => 
  array (
    'method' => 'PUT',
    'path' => '/analytics/reports/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'put_CmsNavigationMenu' => 
  array (
    'method' => 'PUT',
    'path' => '/cms/navigation/{handle}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'handle',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'put_ConsentPolicy' => 
  array (
    'method' => 'PUT',
    'path' => '/privacy/consent',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'put_ContentTypesUpdate' => 
  array (
    'method' => 'PUT',
    'path' => '/content-types/{handle}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'handle',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'put_ContentUpdate' => 
  array (
    'method' => 'PUT',
    'path' => '/content/{type}/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'type',
        'in' => 'path',
        'required' => true,
      ),
      1 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => false,
  ),
  'put_Forum_acceptAnswer' => 
  array (
    'method' => 'PUT',
    'path' => '/forum/threads/{id}/accepted-answer',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'put_Forum_acp_delegation' => 
  array (
    'method' => 'PUT',
    'path' => '/forum/acp/delegation/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'put_Forum_acp_policy' => 
  array (
    'method' => 'PUT',
    'path' => '/forum/acp/policy',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'put_Forum_acp_theme' => 
  array (
    'method' => 'PUT',
    'path' => '/forum/acp/theme',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'put_Forum_answerVote' => 
  array (
    'method' => 'PUT',
    'path' => '/forum/posts/{id}/answer-vote',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'put_Forum_blockConversationUser' => 
  array (
    'method' => 'PUT',
    'path' => '/forum/conversation-blocks/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'put_Forum_boardWatch' => 
  array (
    'method' => 'PUT',
    'path' => '/forum/boards/{id}/watch',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'put_Forum_notificationPreferences' => 
  array (
    'method' => 'PUT',
    'path' => '/forum/notification-preferences',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'put_Forum_pollVote' => 
  array (
    'method' => 'PUT',
    'path' => '/forum/threads/{id}/poll/vote',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'put_Forum_reaction' => 
  array (
    'method' => 'PUT',
    'path' => '/forum/posts/{id}/reaction',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'put_Forum_resolveConversationReport' => 
  array (
    'method' => 'PUT',
    'path' => '/forum/conversation-reports/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'put_Forum_reviewPolicy' => 
  array (
    'method' => 'PUT',
    'path' => '/forum/boards/{id}/review-policy',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'put_Forum_saveAchievementRule' => 
  array (
    'method' => 'PUT',
    'path' => '/forum/achievement-rules/{code}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'code',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'put_Forum_saveConversationSettings' => 
  array (
    'method' => 'PUT',
    'path' => '/forum/conversation-settings',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'put_Forum_saveDraft' => 
  array (
    'method' => 'PUT',
    'path' => '/forum/drafts/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'put_Forum_setBoardPermissions' => 
  array (
    'method' => 'PUT',
    'path' => '/forum/boards/{id}/permissions',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'put_Forum_setCategoryPermissions' => 
  array (
    'method' => 'PUT',
    'path' => '/forum/categories/{id}/permissions',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'put_Forum_setGroupMembership' => 
  array (
    'method' => 'PUT',
    'path' => '/forum/members/{id}/groups',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'put_Forum_setProfileImage' => 
  array (
    'method' => 'PUT',
    'path' => '/forum/account/images/{kind}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'kind',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'put_Forum_setRelationship' => 
  array (
    'method' => 'PUT',
    'path' => '/forum/members/{id}/relationship',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'put_Forum_threadMetadata' => 
  array (
    'method' => 'PUT',
    'path' => '/forum/threads/{id}/metadata',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'put_Forum_updateProfileField' => 
  array (
    'method' => 'PUT',
    'path' => '/forum/profile-fields/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'put_Forum_watch' => 
  array (
    'method' => 'PUT',
    'path' => '/forum/threads/{id}/watch',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'put_Forum_watchPreferences' => 
  array (
    'method' => 'PUT',
    'path' => '/forum/watch-settings',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
  'put_IntegrationsSecret' => 
  array (
    'method' => 'PUT',
    'path' => '/integrations/{id}/secret',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'put_MigrationProjectMapping' => 
  array (
    'method' => 'PUT',
    'path' => '/migration/projects/{id}/mapping',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'put_MigrationProjectUpdate' => 
  array (
    'method' => 'PUT',
    'path' => '/migration/projects/{id}',
    'parameters' => 
    array (
      0 => 
      array (
        'name' => 'id',
        'in' => 'path',
        'required' => true,
      ),
    ),
    'bodyRequired' => true,
  ),
  'put_TrackingConfig' => 
  array (
    'method' => 'PUT',
    'path' => '/tracking/counters',
    'parameters' => 
    array (
    ),
    'bodyRequired' => true,
  ),
);
}
