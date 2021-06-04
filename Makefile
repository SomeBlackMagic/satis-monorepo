
build: ## build environment
	docker build -f .docker/Dockerfile . -t satis-monorepo

#--user $(id -u):$(id -g)
test-example: $(eval SHELL:=/bin/bash)
	docker run --rm --init  --volume $$(pwd)/example:/build  satis-monorepo
