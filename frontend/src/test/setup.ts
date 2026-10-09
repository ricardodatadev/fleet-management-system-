import '@testing-library/jest-dom/vitest';
import { cleanup, configure } from '@testing-library/react';
import { toHaveNoViolations } from 'jest-axe';
import { afterAll, afterEach, beforeAll, expect } from 'vitest';
import { server } from './server';
import { DEFAULT_VIEWPORT, installMatchMedia, setViewport } from './viewport';

expect.extend(toHaveNoViolations);

// findBy/waitFor: 1s padrão é curto sob carga (suite inteira em paralelo).
configure({ asyncUtilTimeout: 5000 });

// APIs de layout/ponteiro ausentes no jsdom e usadas pelos primitivos Radix (Popper, Select, Tooltip).
class ResizeObserverStub {
  observe() {}
  unobserve() {}
  disconnect() {}
}
globalThis.ResizeObserver ??= ResizeObserverStub;
Element.prototype.scrollIntoView ??= function scrollIntoView() {};
Element.prototype.hasPointerCapture ??= () => false;
Element.prototype.releasePointerCapture ??= () => {};
installMatchMedia();

beforeAll(() => {
  setViewport(DEFAULT_VIEWPORT);
  server.listen({ onUnhandledRequest: 'error' });
});
afterEach(() => {
  cleanup();
  localStorage.clear();
  setViewport(DEFAULT_VIEWPORT);
  server.resetHandlers();
});
afterAll(() => server.close());
