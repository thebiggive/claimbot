# Run a sample command

To allow calls to local, e.g. to ping the Local Test Service:

    docker-compose run --rm app composer run messenger:consume

## What the consumer does

As you can see in [`composer.json`](./composer.json)'s `scripts`, the `messenger:consume`
PHP app command is used, which is built into Symfony Messenger. This means no complexity
from maintaining or unit testing our own Command. We rely on the `BatchHandlerInterface`
added in Messenger v5.4 to process batches of 50 donations in non-unit-test environments.

## Service dependency notes

### Queues and locking

Because the live SQS queues are FIFO they guarantee at-most-once delivery of a given message.
So even if something unexpected happened it should not be possible for the same messages to
be double-claimed. For this reason and to benefit from the simplicity of using Symfony
Messenger's `:consume` command directly instead of writing our own, we don't have explicit
run-once locks via Symfony Lock or similar in this app.

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

## Project structure

We loosely follow Slim's directory structure for DI dependencies etc., which is
relatively unopinionated, to match other TBG apps. We don't actually use Slim
itself since there are no web routes. Like our other PHP apps we use several Symfony
libraries, which tend to play well together.
