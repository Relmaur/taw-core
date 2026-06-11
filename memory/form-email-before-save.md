---
name: form-email-before-save
description: Form submissions are saved BEFORE email is sent — failed email is logged but does not prevent a success response
metadata:
  type: project
---

In `Form::process()`, execution order is:

1. Validate fields → return errors if any
2. `SubmissionsHandler::saveSubmission(...)` — **always runs**
3. `$this->sendEmail($data)` — failure is logged via `error_log()` but does NOT fail the request

This was changed from the previous order (email before save) where a failed email lost the submission entirely.

**How to apply:** If someone reports missing submissions, the CPT record should always exist now. Email delivery failures are logged with the prefix `[TAW Form]` in the PHP error log. If submissions ARE missing, the issue is in `SubmissionsHandler::saveSubmission()`, not email.
