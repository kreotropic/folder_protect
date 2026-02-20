#!/bin/bash

# --- CONFIGURAÇÃO ---
NC_URL="http://localhost:8080"      # Ajustado para porta 8080 (comum em dev)
USER="ncadmin"                      # O teu utilizador
PASS="yura"                         # A tua password
FOLDER="teste"                      # A pasta protegida que queres testar
# --------------------

TARGET_URL="$NC_URL/remote.php/dav/files/$USER/$FOLDER"

echo "--- 1. A obter ETag atual ---"
# Pedimos o getetag via PROPFIND
RESPONSE1=$(curl -s -k -i -X PROPFIND -u "$USER:$PASS" "$TARGET_URL" \
  -H "Depth: 0" \
  -d '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:getetag/></d:prop></d:propfind>')

ETAG1=$(echo "$RESPONSE1" | grep -oP '(?<=<d:getetag>).*?(?=</d:getetag>)')

if [ -z "$ETAG1" ]; then
    echo "❌ ERRO CRÍTICO: Não foi possível ler o ETag."
    echo "   URL usado: $TARGET_URL"
    echo "   Verifica se a pasta '$FOLDER' existe e se as credenciais estão corretas."
    echo "   Resposta do servidor (início):"
    echo "$RESPONSE1" | head -n 10
    exit 1
fi

echo "ETag Inicial: $ETAG1"
echo ""

echo "--- 2. A tentar apagar (DELETE) ---"
# Tentamos apagar. Esperamos um 423 Locked.
RESPONSE_DEL=$(curl -s -k -w "\n%{http_code}" -X DELETE -u "$USER:$PASS" "$TARGET_URL")
HTTP_CODE=$(echo "$RESPONSE_DEL" | tail -n1)
echo "Código HTTP retornado: $HTTP_CODE (Esperado: 423)"

if [ "$HTTP_CODE" != "423" ]; then
    echo "⚠️  Aviso: O código não foi 423. Resposta:"
    echo "$RESPONSE_DEL" | head -n -1
fi
echo ""

echo "--- 3. A verificar novo ETag ---"
ETAG2=$(curl -s -k -X PROPFIND -u "$USER:$PASS" "$TARGET_URL" \
  -H "Depth: 0" \
  -d '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:getetag/></d:prop></d:propfind>' \
  | grep -oP '(?<=<d:getetag>).*?(?=</d:getetag>)')

echo "ETag Final:   $ETAG2"
echo ""

if [ "$ETAG1" != "$ETAG2" ]; then
    echo "✅ SUCESSO: O ETag mudou! O cliente Windows deve restaurar a pasta agora."
else
    echo "❌ FALHA: O ETag é igual. O cliente pode não perceber a mudança."
fi
