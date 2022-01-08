FROM php:7.4-cli

COPY . /usr/src/kustomize-storage-config-transformer
WORKDIR /usr/src/kustomize-storage-config-transformer

RUN groupadd -r transformer; \
    useradd --no-log-init -r -g transformer transformer

USER transformer

CMD [ "./bin/transform-storage-config" ]
