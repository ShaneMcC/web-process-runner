# web-process-runner

This repo contains a small app that exposes the ability to run certain scripts
to the web.

Scripts are run by calling `/<KEY>/run/scriptFile.sh` which will run the file
`scriptFile.sh` within the `/app/scripts` directory. The web call will return
immediately with a job id to confirm the job has started.

The script will receive json on `STDIN`, with post data being provided under the
'data' key. (More fields may be added to this in future)

You can then call `/<KEY>/info/<JOBID>` to get status on the job (eg if it is
still running, or exited and stdout and the exitCode.)

`/<KEY>/signal/<JOBID>/<SIGNAL>` can be used to signal a running job

Config settings are handled as env vars:

**`KEY`**: Key string that must be provided in all URLs before the commands.

**`PORT`**: What port to listen on (Default: 8010)

**`LOGLEVEL`**: What level of logging to use

**`SCRIPTS`**: If you want to use an alternative scripts directory than `/app/scripts`

**`JOBHISTORY`**: How long (in seconds) to remember info for processes that have exited.
