---
name: form-email-before-save
description: Form submissions are saved to the CPT only AFTER email sends successfully — a failed email means no submission record
metadata:
  type: project
---

In `Form::process()`, execution order is:

1. Validate fields → return errors if any
2. `$this->sendEmail($data)` — if this returns `false`, call `wp_send_json_error()` and **return**
3. `SubmissionsHandler::saveSubmission(...)` — only reached if email succeeded

A failed `wp_mail()` or MJML template error means the submission is lost with no record in WP Admin → Submissions.

**Why:** This was a deliberate ordering choice — the assumption is that if email fails, something is misconfigured and the operator should be alerted via the error response. Saving a submission the operator can't act on (because notifications are broken) was considered lower priority.

**How to apply:** If someone reports missing submissions, check `wp_mail()` delivery first — not the CPT query. If the use case requires guaranteed submission persistence regardless of email status, the fix is to reorder: save first, then email (and accept that failed emails still produce a saved record).
