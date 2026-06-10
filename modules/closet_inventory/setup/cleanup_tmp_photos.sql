-- One-shot cleanup for photo rows that pointed at the old /tmp fallback path.
-- Those files do not survive a reboot or systemd-tmpfiles sweep, so the rows
-- are orphans and ActionPhotoView returns 404 file missing for them.
--
-- Review before running:
--   SELECT id, closet_uid, label, path FROM tcs_closet_photos WHERE path LIKE '/tmp/%';
--
-- Then to delete:
DELETE FROM tcs_closet_photos WHERE path LIKE '/tmp/%';
