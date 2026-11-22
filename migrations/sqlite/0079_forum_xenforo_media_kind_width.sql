-- SQLite VARCHAR(N) has no enforced length; the existing TEXT-affinity column already
-- stores XenForo private media kinds. Keep numbered migration parity across drivers.
SELECT 1;
