import { setupServer } from 'msw/node';

/** Servidor MSW compartilhado; handlers por teste via `server.use(...)`. */
export const server = setupServer();
