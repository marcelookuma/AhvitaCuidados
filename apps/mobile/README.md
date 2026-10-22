# App Android/iOS (fase 2)

O MVP é a web (PWA instalável). O app nativo entra sem mudar a API nem as regras.

## Stack prevista
- **Expo (React Native) + TypeScript**, um código para Android e iOS. Prioridade: Android (a maioria dos(as) cuidadores(as)).
- **`@cuidados/core`** reaproveitado como está: tipos, cliente da API, fila offline, catálogo de registros, lembretes, permissões.

## O que muda em relação à web
| Tema | Web (hoje) | App |
|---|---|---|
| Sessão | cookie httpOnly | `criarApi({ base, token: () => SecureStore.get('token') })` — Bearer guardado no cofre do aparelho |
| Fila offline | IndexedDB | `Armazenamento` em `expo-sqlite`; fotos/áudios em `FileSystem.documentDirectory` (fora da galeria) |
| Avisos | consulta a cada 5 s | **push** (FCM/APNs via Expo Notifications) a partir do outbox `eventos_saida`; consulta só com o app aberto |
| Foto | `<input capture>` | `expo-camera` direto, sem salvar na galeria |
| Áudio | MediaRecorder | `expo-av` (m4a), aceito pela API |
| Voz | speechSynthesis | `expo-speech` |
| Lembrete de remédio | só com o app aberto | notificação local agendada pelos horários (`lembretes.ts`) |

## Mudanças na API para o app (pequenas)
1. `POST /dispositivos` para registrar o token de push (nova tabela `dispositivos`).
2. Worker do outbox enviando push (cron de 1 min ou serviço dedicado na fase 2).
3. `GET /midias/{id}` já aceita Bearer; o app baixa com cabeçalho.

## Lojas
- Google Play: conta de desenvolvedor, política de dados de saúde (formulário "Data safety"), público inclui crianças → seguir política de famílias.
- App Store: "Health & Medical", privacidade nutricional, login sem senha permitido.
