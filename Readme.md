# Run a sample command

To allow calls to local, e.g. to ping the Local Test Service:

    docker-compose run --rm app composer run claimbot:claim

## Running HMRC's Local Test Service

This readme primarily assumes a *nix environment. HMRC's own documentation is for Windows and some of the workarounds
here may not be required if you are running Windows and follow their steps.

Because HMRC require Java [1.]7 specifically, you must:

* get the old binary from Oracle for your platform and install that alongside any modern Java versions
* navigate to the LTS location, e.g. `cd ~/devtools/HMRCTools/LTS`
* ensure your normal env vars have `$LTS_HOME` set to the full path of the above, e.g. in your profile script `~/.zshrc` on modern macOS versions.
* set `$JAVA_HOME` to the version [1.]7 Java at runtime as you start the service: `JAVA_HOME=$(/usr/libexec/java_home -v 1.7) ./RunLTSStandalone.sh`

### Pre-First Run

Before the above commands works you must run the Update Manager once.

* navigate to the LTSUM location, e.g. `cd ~/devtools/HMRCTools/LTSUM`
* fix permissions from HMRC's archive: `chmod u+x RunUpdateManager.sh`
* `JAVA_HOME=$(/usr/libexec/java_home -v 1.7) ./RunUpdateManager.sh`

### Manual local XML tests

When the server is running, you can upload an XML file in a browser at [localhost:5665/LTS/](http://localhost:5665/LTS/).
