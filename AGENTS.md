# Project workflow

- Work directly in this checkout and publish completed, checked changes to `main` in `barghbazar1400-coder/crmbarghbazar`.
- Do not create feature branches, pull requests, or extra worktrees unless the user explicitly requests them.
- Preserve existing attendance, leave, user and permission behavior.
- The user authorizes deployment after successful checks. If a configured deployment connection is available, use it after publishing. Otherwise report that deployment has not run and provide the deployment command; never claim a GitHub commit updates the live site by itself.
- Git tree objects are normal commit internals, not separate branches.
- The user confirms hosting already polls `main` using a cron deployment. Publishing to `main` is sufficient for the scheduled rollout. The manual hosting command is `bash "$HOME/.crmbarghbazar-deploy/deploy.sh"`. Do not request repeated deployment confirmation or claim live verification without observing the site.
