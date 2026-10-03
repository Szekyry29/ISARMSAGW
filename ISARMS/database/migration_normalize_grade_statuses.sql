-- Normalize grade workflow statuses.
-- Existing values are preserved semantically:
-- Approved -> Posted, Submitted -> Pending, Flagged -> Returned.

ALTER TABLE grades
MODIFY COLUMN status ENUM('Pending','Submitted','Approved','Posted','Flagged','Returned') NOT NULL DEFAULT 'Pending';

UPDATE grades SET status = 'Posted' WHERE status = 'Approved';
UPDATE grades SET status = 'Pending' WHERE status = 'Submitted';
UPDATE grades SET status = 'Returned' WHERE status = 'Flagged';
UPDATE grades SET status = 'Pending' WHERE status = '' OR status IS NULL;

ALTER TABLE grades
MODIFY COLUMN status ENUM('Pending','Posted','Returned') NOT NULL DEFAULT 'Pending';
