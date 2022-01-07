#!/usr/bin/env bash

##
# @file
#   Build a Docker image for this tool.
#
#   Usage:
#   ./publish.sh [docker image tag]
#
#   If the tag name is not provided, "latest" is assumed.
#
#   Copyright 2022 Inveniem. All rights reserved.
#
# @author Guy Elsmore-Paddock (guy@inveniem.com)
#

# Stop on undefined variables
set -u

# Stop on non-zero exit
set -e

##
# The path to this script.
#
# shellcheck disable=SC2034
script_dir="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null 2>&1 && pwd )"

################################################################################
# Constants                                                                    #
################################################################################

##
# The tag for the Docker image.
#
TAG="${1:-latest}"

################################################################################
# Main Script Body
################################################################################
build_date=$(date --rfc-3339=seconds)
project_vcs_revision=$(git rev-parse --short HEAD)

container_name="inveniem/kustomize-storage-generator:${TAG}"

docker build \
  --build-arg "VCS_REF=${project_vcs_revision}" \
  --build-arg "BUILD_DATE=${build_date}" \
  -t "${container_name}" \
  .
