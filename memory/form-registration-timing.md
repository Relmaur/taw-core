---
name: form-registration-timing
description: Why forms must be registered in boot() not in templates — admin-ajax.php never runs theme templates
metadata:
  type: project
---

Forms must be registered in `MetaBlock::boot()` (wrapped in `add_action('init', ...)`), never inside a template file.

**Why:** WordPress AJAX submissions go to `admin-ajax.php`, which never loads theme templates. Any `Form::register()` call inside a template simply doesn't execute on the AJAX request, so the handler doesn't exist and the submission fails with a "0" or "invalid nonce" response.

**How to apply:** If someone puts a `Form::register()` call in a template and asks why AJAX form submissions fail, this is the root cause. The fix is always to move registration into `boot()`.
