# The Laravel app (and its ddev project) lives in hub/; forward every target there.
.PHONY: dev up install migrate fresh test
dev up install migrate fresh test:
	@$(MAKE) -C hub $@
