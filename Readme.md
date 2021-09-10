# Run a sample command

To allow calls to local, e.g. to ping the Local Test Service:

    docker-compose run --rm app composer run claimbot:claim

## Running HMRC's Local Test Service

Because HMRC require Java [1.]7 specifically, you must:

* get the old binary from Oracle for your platform and install that alongside any modern Java versions
* navigate to the LTS location, e.g. `cd ~/devtools/HMRCTools/LTS`
* ensure your normal env vars have `$LTS_HOME` set to the full path of the above, e.g. in your profile script `~/.zshrc` on modern macOS versions.
* set `$JAVA_HOME` to the version [1.]7 Java at runtime as you start the service: `JAVA_HOME=$(/usr/libexec/java_home -v 1.7) ./RunLTSStandalone.sh `
