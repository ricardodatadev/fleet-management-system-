// Verificação manual do handshake Socket.io autenticado (F1-18), de fora da stack, pela porta do nginx.
// Uso (imagem de teste, que tem o socket.io-client):
//   docker run --rm --network host -e WS_URL=ws://localhost:8080 -e TOKEN=... \
//     -v "$PWD/services/node-realtime/scripts:/srv/scripts:ro" gof/node-realtime:test node scripts/handshake-check.mjs
// Sem TOKEN, conecta sem token. Imprime uma linha JSON: {"result":"ready",...} ou {"result":"error","message":...}.
// Não imprime o token.
import { io } from 'socket.io-client';

const url = process.env.WS_URL ?? 'ws://localhost:8080';
const token = process.env.TOKEN;
const started = Date.now();
const socket = io(url, {
  path: '/socket.io/',
  transports: ['websocket'],
  reconnection: false,
  timeout: 5000,
  ...(token ? { auth: { token } } : {}),
});
const done = (out) => {
  console.log(JSON.stringify({ ...out, ms: Date.now() - started }));
  socket.close();
  process.exit(0);
};
socket.on('session:ready', (payload) => done({ result: 'ready', user: payload.user, rooms: payload.rooms }));
socket.on('connect_error', (err) => done({ result: 'error', message: err.message }));
setTimeout(() => done({ result: 'timeout' }), 8000);
