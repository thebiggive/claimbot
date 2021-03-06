# ClaimBot

Microservice for submitting Gift Aid claims

## Simulate claiming locally

### Prepare MatchBot

To do a realistic local run you should normally be running [MatchBot](https://github.com/thebiggive/matchbot)
too and have it send some data to the queue.

You might for example:

* Put some eligible-for-claims donation data into your local/Docker database, manually
  or with [the Donate frontend](https://github.com/thebiggive/donate-frontend).
* Consider temporarily tweaking MatchBot's `DonationRepository::findReadyToClaimGiftAid()`
  and/or ClaimBot's `max_batch_size` in the [`settings`](./app/settings.php).

### Publish messages

In the **MatchBot** project folder:

    docker-compose run --rm app composer run matchbot:claim-gift-aid

### Consume messages

In the **ClaimBot** project folder:

    docker-compose run --rm consumer

### Run a one-off poll command

    docker-compose run --rm consumer composer run claimbot:poll some-correlation-id

## Run unit tests

    docker-compose run --rm consumer composer run test

## What the consumer does

As you can see in [`composer.json`](./composer.json)'s `scripts`, the `messenger:consume`
PHP app command is used, which is built into Symfony Messenger. This means no complexity
from maintaining or unit testing our own Command. We process batches of up to 1,000 donations
per claim in non-unit-test environments.

## Service dependency notes

### Queues and locking

The timing of live tasks' schedules in the `infrastructure` repository, and choice of
`--time-limit` in this repo's `composer.json` task, should be designed to avoid overlap
of consumers.

However we also dynamically append a `&consumer` to the DSN in `settings.messenger.inbound_dsn`
to reduce the [risk of double consumes](https://symfony.com/doc/current/messenger.html#redis-transport).

This hopefully makes the Redis approach reasonably safe, while avoiding the limitations
of SQS FIFO queues which meant we could not process batches of more than 10 donations using
Messenger's `BatchHandlerInterface`.

Given the above risk mitigations and to benefit from the simplicity of using Symfony
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

To have your local ClaimBot in Docker send data to the LTS, uncomment the
line in [dependencies.php](./app/dependencies.php) marked

> ... // Uncomment to use LTS rather than ETS

and this one:

> $ga->setTimestamp(new \DateTime());

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
