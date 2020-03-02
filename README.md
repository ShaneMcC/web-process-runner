# web-process-runner

This repo contains a small app that exposes the ability to run certain scripts to the web.

I mostly use this for auto-deployment from webhooks from dockerhub/github etc (add a `update.sh` to the scripts dir, then have the webhook call that which will do the appropriate things to update the containers.)

This is loosely based off the idea from [iaincollins/docker-deploy-webhook](https://github.com/iaincollins/docker-deploy-webhook) except allowing for user-customised actions rather than specifically updating a service on swarm.

## How to use

Scripts are run by calling `/<KEY>/run/scriptFile.sh` which will run the file `scriptFile.sh` within the `/app/scripts` directory. The web call will return immediately with a job id to confirm the job has started.

The script will receive json on `STDIN`, with post data being provided under the 'data' key. (More fields may be added to this in future)

You can then call `/<KEY>/info/<JOBID>` to get status on the job (eg if it is still running, or exited and stdout and the exitCode.)

`/<KEY>/signal/<JOBID>/<SIGNAL>` can be used to signal a running job

## Config settings

Config settings are handled as env vars:

**`KEY`**: Key string that must be provided in all URLs before the commands.

**`PORT`**: What port to listen on (Default: 8010)

**`LOGLEVEL`**: What level of logging to use

**`SCRIPTS`**: If you want to use an alternative scripts directory than `/app/scripts`

**`JOBHISTORY`**: How long (in seconds) to remember info for processes that have exited.

## Comments, Questions, Bugs, Feature Requests etc.

Bugs and Feature Requests should be raised on the [issue tracker on github](https://github.com/ShaneMcC/web-process-runner/issues), and code pull requests via github are appreciated and welcome, though I may not merge them all.

I can be found idling on various different IRC Networks, but the best way to get in touch would be to message "Dataforce" on Quakenet, or drop me a mail (email address is in my [github profile](https://github.com/ShaneMcC))
