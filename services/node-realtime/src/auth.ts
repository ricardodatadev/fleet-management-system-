import type { Server } from 'socket.io';

/**
 * F1-05: toda conexão é recusada. O handshake autenticado (Bearer → Laravel /auth/me)
 * é entregue na F1-18; até lá nenhum socket pode ficar aberto sem autenticação.
 */
export function rejectAllConnections(io: Server): void {
  io.use((_socket, next) => {
    next(new Error('not_implemented'));
  });
}
