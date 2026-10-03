-- Post existing complete grade records that were saved under the old review workflow.
-- Leave empty drafts and grades already returned for correction unchanged.
UPDATE grades
SET status = 'Posted',
    postedGrade = gradeEquivalent,
    submittedAt = COALESCE(submittedAt, encodedAt, NOW()),
    approvedAt = NULL,
    approvedByID = NULL
WHERE status = 'Pending'
  AND submittedAt IS NOT NULL
  AND computedGrade IS NOT NULL
  AND gradeEquivalent IS NOT NULL
  AND dateDeleted IS NULL;