import * as path from 'path';
import * as fs from 'fs';
import { test, expect } from '../fixtures/setup-fixtures';

const ASSETS_DIR = path.resolve(__dirname, '../assets');

test.describe('hn/video drag-uploader override', () => {
  let consoleErrors: string[];
  let pageErrors: string[];

  test.beforeEach(async ({ page }) => {
    consoleErrors = [];
    pageErrors = [];
    page.on('pageerror', (err) => pageErrors.push(`${err.name}: ${err.message}`));
    page.on('console', (msg) => {
      if (msg.type() !== 'error') return;
      const text = msg.text();
      // Chromium emits this when COOP/COEP is set on a non-localhost http origin.
      // We work around the requirement via --unsafely-treat-insecure-origin-as-secure,
      // but the warning is still printed. Filter it.
      if (text.includes('Cross-Origin-Opener-Policy header has been ignored')) return;
      consoleErrors.push(text);
    });
  });

  test('cross-origin isolation headers are set on the backend', async ({ page }) => {
    const response = await page.request.get('/typo3/');
    expect(response.headers()['cross-origin-opener-policy']).toBe('same-origin');
    expect(response.headers()['cross-origin-embedder-policy']).toBe('require-corp');
  });

  test('crossOriginIsolated is enabled in the backend', async ({ page }) => {
    await page.goto('/typo3/main');
    await page.waitForLoadState('networkidle');

    const flags = await page.evaluate(() => ({
      crossOriginIsolated: self.crossOriginIsolated,
      hasSharedArrayBuffer: typeof SharedArrayBuffer !== 'undefined',
    }));
    expect.soft(flags.crossOriginIsolated, 'crossOriginIsolated must be true').toBe(true);
    expect.soft(flags.hasSharedArrayBuffer, 'SharedArrayBuffer must be defined').toBe(true);
  });

  test('importmap rewires drag-uploader to the hn/video override', async ({ page }) => {
    await page.goto('/typo3/main');
    await page.waitForLoadState('networkidle');

    // Read the importmap from the document and resolve the override URL.
    const dragUploaderUrl = await page.evaluate(() => {
      const scripts = Array.from(document.querySelectorAll<HTMLScriptElement>('script[type="importmap"]'));
      for (const s of scripts) {
        try {
          const map = JSON.parse(s.textContent || '{}');
          const v = map && map.imports && map.imports['@typo3/backend/drag-uploader.js'];
          if (typeof v === 'string') return v;
        } catch (_) {
          // ignore malformed importmap
        }
      }
      return null;
    });

    expect(dragUploaderUrl, 'no @typo3/backend/drag-uploader.js entry in importmap').not.toBeNull();

    // Assets are published under a hashed path in TYPO3. The exact URL is
    // implementation detail — what matters is the file the importmap points
    // to is OUR override (i.e. it imports the original drag-uploader from
    // @hn/video/typo3/backend/drag-uploader.js).
    const response = await page.request.get(dragUploaderUrl!);
    expect(response.ok(), `failed to fetch ${dragUploaderUrl}`).toBe(true);
    const body = await response.text();
    expect(body).toContain('@hn/video/typo3/backend/drag-uploader.js');
    expect(body).toMatch(/processFiles/);
  });

  // Run the converter on each fixture: a small h264 mp4 (fast-path: copy)
  // and a vp9 webm (must re-encode to h264).
  for (const fx of ['sample-h264-720p.mp4', 'sample-vp9.webm'] as const) {
    test(`ffmpeg.wasm conversion runs end-to-end on ${fx}`, async ({ page }) => {
      test.setTimeout(180_000);

      const fixture = path.join(ASSETS_DIR, fx);
      expect(fs.existsSync(fixture), `Missing fixture ${fixture}`).toBe(true);
      const fixtureBytes = fs.readFileSync(fixture);
      const mime = fx.endsWith('.mp4') ? 'video/mp4' : 'video/webm';

      await page.goto('/typo3/main');
      await page.waitForLoadState('networkidle');

      await page.evaluate(({ b64, name, mime: m }) => {
        const bin = atob(b64);
        const bytes = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
        (window as any).__videoBlob = new File([bytes], name, { type: m });
      }, { b64: fixtureBytes.toString('base64'), name: fx, mime });

      const result = await page.evaluate(async () => {
        try {
          const importer = new Function('s', 'return import(s)') as (s: string) => Promise<any>;
          const mod = await importer('@hn/video/video-converter.js');
          const inFile: File = (window as any).__videoBlob;
          let lastProgress = 0;
          const out: File = await mod.createMp4File(inFile, (p: number) => { lastProgress = p; });
          return {
            ok: true as const,
            name: out.name,
            type: out.type,
            size: out.size,
            progress: lastProgress,
          };
        } catch (err: any) {
          return { ok: false as const, error: String(err && err.message ? err.message : err) };
        }
      });

      if (!result.ok) {
        throw new Error(`video conversion failed: ${result.error}`);
      }
      expect(result.name).toMatch(/\.mp4$/);
      expect(result.type).toBe('video/mp4');
      expect(result.size).toBeGreaterThan(100);

      expect(pageErrors, `pageerror: ${pageErrors.join('\n')}`).toHaveLength(0);
      expect(consoleErrors, `console errors: ${consoleErrors.join('\n')}`).toHaveLength(0);
    });
  }
});
