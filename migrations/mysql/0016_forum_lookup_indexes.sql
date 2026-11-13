-- Support latest discussions, member history, subscriptions and FK cascades.
CREATE INDEX ce_forum_thread_latest ON ce_forum_threads(updated_at, id);
CREATE INDEX ce_forum_thread_author ON ce_forum_threads(author_id, updated_at, id);
CREATE INDEX ce_forum_post_author ON ce_forum_posts(author_id);
CREATE INDEX ce_forum_watch_user ON ce_forum_watches(user_id, thread_id);
CREATE INDEX ce_forum_reaction_user ON ce_forum_reactions(user_id);
CREATE INDEX ce_forum_report_post ON ce_forum_reports(post_id);
CREATE INDEX ce_forum_report_user ON ce_forum_reports(user_id);
CREATE INDEX ce_forum_report_resolver ON ce_forum_reports(resolved_by);
