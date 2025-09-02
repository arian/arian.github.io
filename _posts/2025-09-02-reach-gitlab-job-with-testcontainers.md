---
title: Let Sibling Containers in GitLab CI talk back
---

When using GitLab CI with `docker:dind`, your job runs in a container and any containers you start (e.g. with [Testcontainers](https://testcontainers.com/)) become _sibling_ containers. If a sibling container needs to reach a server running in your CI job, the usual `localhost` won't work.

**TL;DR**: Use `hostname -i` to get your job container's IP and pass it via `extra_hosts`.

## The Problem

This fails because sibling containers can't reach each other via `localhost`:

```yml
test:
  stage: test
  image: python:3.12
  variables:
    DOCKER_HOST: "tcp://docker:2375"
    DOCKER_TLS_CERTDIR: ""
    DOCKER_DRIVER: overlay2
  services:
    - name: docker:dind
      command: ["--tls=false"]
  script:
    - pip install docker
    - echo "Start local http server"
    - python -m http.server & server_pid=$!
    - echo "Try to reach the server started in the CI job"
    - >
      python -c "import os, docker;
      docker.from_env().containers.run(
          'quay.io/curl/curl:latest',
          'http://localhost:8000',
      )"
    - kill $server_pid
```


This will fail as `curl` can't reach `localhost:8000`.

Inside the job container you bind your app to `0.0.0.0:<port>`.
From the sibling container you naively try:

- `localhost:<port>`: fails, this refers to the local sibling container
- `host.docker.internal`: fails, this would refer to the GitLab CI host

Result: connection refused.

## Solution

We can use `hostname -i` to find the job container IP. If we pass it to the
siblign container, it can use that to reach the server running in the CI job.

we can pass it as `extra_hosts` and use a stable alias, e.g. `jobhost.local`,
to refer to the IP of the CI job container, instead of the direct IP. The
`extra_hosts` sets the `/etc/hosts` in the sibling container.

An alternave solution I found was to use `host.testcontainers.internal`, but
that seems to be implemented only for testcontainers in Java/Go/node.js/.NET.
Not for Testcontainer Python that I'm using in my project.

### Extracting the IPv4

Pick the first IPv4 (ignore IPv6, loopback):

```bash
hostname -i | awk '{print $2}'
```

### Use It in your job

```yml
test:
  stage: test
  image: python:3.12
  variables:
    DOCKER_HOST: "tcp://docker:2375"
    DOCKER_TLS_CERTDIR: ""
    DOCKER_DRIVER: overlay2
  services:
    - name: docker:dind
      command: ["--tls=false"]
  script:
    - pip install docker
    - export CI_JOB_IP=$(hostname -i | awk '{print $2}')
    - echo $CI_JOB_IP
    - echo "Start local http server"
    - python -m http.server & server_pid=$!
    - echo "Try to reach the server started in the CI job"
    - >
      python -c "import os, docker;
      docker.from_env().containers.run(
          'quay.io/curl/curl:latest',
          'http://job.local:8000',
          extra_hosts={'job.local': os.getenv('CI_JOB_IP')}
      )"
    - kill $server_pid
```

Now the CI job succeeds, with these logs:

```
$ export CI_JOB_IP=$(hostname -i | awk '{print $2}')
$ echo $CI_JOB_IP
172.17.0.4
$ echo "Start local http server"
Start local http server
$ python -m http.server & server_pid=$!
$ echo "Try to reach the server started in the CI job"
Try to reach the server started in the CI job
$ python -c "import os, docker; docker.from_env().containers.run( # collapsed multi-line command
172.17.0.3 - - [02/Sep/2025 15:11:09] "GET / HTTP/1.1" 200 -
$ kill $server_pid
```

## Conclusion

By discovering the CI job container's IP and using `extra_hosts`, sibling containers can communicate back to services in the main job container. This simple technique works well for testing scenarios with Testcontainers or similar setups.
