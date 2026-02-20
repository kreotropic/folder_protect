# Arquitetura Nextcloud (Ambiente de Desenvolvimento)

## Infraestrutura

| Componente | Detalhe |
|---|---|
| Tipo | Docker standalone (sem compose direto, containers individuais) |
| URL base | `http://localhost:8080` |
| Container app | `nextcloud-app` (imagem `nextcloud:31.0.6-apache`) |
| Container DB | `nextcloud-db` (imagem `mariadb:10.11`) |
| Container Cache | `nextcloud-redis` (imagem `redis:alpine`) |

## Volumes / Mounts

| Tipo | Local no Host | Local no Container |
|---|---|---|
| Volume Docker | `nextcloud-dev_nextcloud` | `/var/www/html` |
| **Bind mount** | `/home/ricardo/nextcloud-dev/apps/` | `/var/www/html/custom_apps/` |

> **Importante:** A pasta de apps é um **bind mount**. Qualquer alteração local em `/home/ricardo/nextcloud-dev/apps/` é imediatamente refletida no container — não é necessário copiar ficheiros nem reiniciar o container para alterações de código PHP.

## Comandos Úteis

```bash
# Ver containers em execução
docker ps

# Executar comando no container Nextcloud
docker exec -it nextcloud-app <comando>

# Executar occ
docker exec -u www-data nextcloud-app php occ <comando>

# Ver logs do Nextcloud
docker exec nextcloud-app tail -f /var/www/html/data/nextcloud.log

# Aceder ao container como root
docker exec -it nextcloud-app bash
```

## Localização das Apps

- **Custom apps (desenvolvimento):** `/home/ricardo/nextcloud-dev/apps/` (host) = `/var/www/html/custom_apps/` (container)
- **App folder_protection:** `/home/ricardo/nextcloud-dev/apps/folder_protection/`
