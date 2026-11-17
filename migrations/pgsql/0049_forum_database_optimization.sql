-- Delivered inbox projections are selected per-recipient in both UNION arms.
CREATE INDEX ce_forum_answer_delivery_idx ON ce_forum_answer_notifications(recipient_id,created_at DESC,event_id DESC) WHERE state=1;
-- For the unread-by-board query, order by activity without scanning a pinned feed.
CREATE INDEX ce_forum_unread_board_idx ON ce_forum_threads(board_id,updated_at DESC,id) WHERE deleted_at IS NULL;
