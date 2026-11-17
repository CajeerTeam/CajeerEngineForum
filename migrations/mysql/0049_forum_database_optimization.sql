-- Recipient-first delivery indexes avoid filesort over the full notification history.
CREATE INDEX ce_forum_answer_delivery_idx ON ce_forum_answer_notifications(recipient_id,state,created_at DESC,event_id DESC);
-- The unread filter orders topics by activity without an unrelated pinned sort key.
CREATE INDEX ce_forum_unread_board_idx ON ce_forum_threads(deleted_at,board_id,updated_at DESC,id);
