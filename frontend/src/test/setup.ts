import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { toHaveNoViolations } from 'jest-axe';
import { afterAll, afterEach, beforeAll, expect } from 'vitest';
import { server } from './server';

expect.extend(toHaveNoViolations);

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

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }));
afterEach(() => {
  cleanup();
  localStorage.clear();
  server.resetHandlers();
});
afterAll(() => server.close());
