import { createBrowserRouter } from 'react-router-dom';
import { HomePage } from '@/routes/HomePage';

// Rotas reais (login, guardas, shell) chegam nas F1-23/F1-24.
export const routes = [{ path: '*', element: <HomePage /> }];

export const router = createBrowserRouter(routes);
