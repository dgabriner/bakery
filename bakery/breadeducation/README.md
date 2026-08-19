# Sour Flour Bread Education

This folder is the deployable `/breadeducation/` learning zone for `bakery.sourflour.org`.

- [`index.html`](index.html) is the learning hub and interactive lab.
- [`fresh-loaf.html`](fresh-loaf.html) is the curated Fresh Loaf reading path, with direct links to the strongest lessons, handbook chapters, forums, bake logs, and troubleshooting threads.
- [`sourdough.html`](sourdough.html), [`fermentation.html`](fermentation.html), [`formula.html`](formula.html), [`bake.html`](bake.html), [`whole-grain.html`](whole-grain.html), and [`troubleshooting.html`](troubleshooting.html) are focused curriculum pages.
- [`yeasted.html`](yeasted.html) is the supporting commercial-yeast and preferment track.
- [`DEBRIEF.md`](DEBRIEF.md) is the research record behind the Fresh Loaf synthesis, including the source trail and the community/forum design lessons carried into SF Baker.
- `.htaccess` keeps directory access predictable and applies basic browser hardening.

To deploy from the repository root, copy `.env.sftp.breadeducation.example` to `.env.sftp.breadeducation`, fill in the credentials, and run:

```powershell
.\scripts\push_breadeducation_sftp.ps1
```

Use `-DryRun` to inspect the upload file list without changing the remote site.
