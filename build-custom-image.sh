#!/bin/bash

# Build and Push Custom Coolify Image
# This script builds our custom Coolify image with Swarm support

set -e

# Configuration
DOCKER_HUB_USERNAME="${DOCKER_HUB_USERNAME:-feproappdev}"
IMAGE_NAME="coolify-swarm"
TAG="${TAG:-latest}"
COOLIFY_VERSION="${COOLIFY_VERSION:-latest}"

FULL_IMAGE="${DOCKER_HUB_USERNAME}/${IMAGE_NAME}:${TAG}"

echo "=========================================="
echo "Building Custom Coolify Image"
echo "=========================================="
echo "Base Coolify version: ${COOLIFY_VERSION}"
echo "Target image: ${FULL_IMAGE}"
echo "=========================================="

# Build the image
echo ""
echo "Building image..."
docker build \
    --build-arg COOLIFY_VERSION=${COOLIFY_VERSION} \
    -f Dockerfile.custom \
    -t ${FULL_IMAGE} \
    .

echo ""
echo "✅ Image built successfully: ${FULL_IMAGE}"

# Ask if user wants to push
read -p "Do you want to push to Docker Hub? (y/n) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    echo ""
    echo "Pushing to Docker Hub..."
    docker push ${FULL_IMAGE}
    echo ""
    echo "✅ Image pushed successfully!"
    echo ""
    echo "=========================================="
    echo "To use this image, update docker-compose.prod.yml:"
    echo "  image: ${FULL_IMAGE}"
    echo "=========================================="
else
    echo ""
    echo "Skipping push. To push later, run:"
    echo "  docker push ${FULL_IMAGE}"
fi

echo ""
echo "Done!"
