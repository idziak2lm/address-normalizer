# API Client Management

All commands run inside the Docker container via `docker exec normalizer-app`.

## Create a new client

```bash
docker exec normalizer-app php artisan api-client:create "client_name" --limit=10000 --provider=openai
```

Options:
- `--limit` — monthly request limit (default: 1000)
- `--provider` — preferred AI provider: `openai` or `anthropic` (default: openai)

The API token is displayed **once** — save it immediately.

## Use the token

```
Authorization: Bearer <your_token>
```

## Other commands

```bash
# List all clients
docker exec normalizer-app php artisan api-client:list

# Generate a new token (invalidates the old one)
docker exec normalizer-app php artisan api-client:rotate-key {id}

# Change monthly limit
docker exec normalizer-app php artisan api-client:set-limit {id} 20000

# Deactivate a client
docker exec normalizer-app php artisan api-client:deactivate {id}
```
