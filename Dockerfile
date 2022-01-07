FROM php:7.4-cli

COPY . /usr/src/kustomize-storage-generator
WORKDIR /usr/src/kustomize-storage-generator

RUN groupadd -r generator; \
    useradd --no-log-init -r -g generator generator

USER generator

CMD [ "./bin/generate-storage" ]
