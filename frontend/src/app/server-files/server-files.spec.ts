import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { RemoteDirectory, RemoteFileEntry } from '../servers/server-api';
import { ServerFiles } from './server-files';

describe('ServerFiles', () => {
  let harness: RouterTestingHarness;
  let component: ServerFiles;
  let http: HttpTestingController;
  const directory: RemoteDirectory = {
    server_name: 'Production',
    root_path: '/home/ftp-user',
    relative_path: '',
    path: '/home/ftp-user',
    parent_relative_path: null,
    entries: [
      { name: 'assets', relative_path: 'assets', type: 'directory', size: null, modified: null },
      { name: 'index.html', relative_path: 'index.html', type: 'file', size: 25, modified: '2026-10-04T12:00:00Z' },
      { name: 'current', relative_path: 'current', type: 'link', size: null, modified: null },
    ],
  };
  const folder = directory.entries[0];
  const file = directory.entries[1];

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ServerFiles],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([{ path: 'server-files/:serverId', component: ServerFiles }]),
      ],
    }).compileComponents();
    http = TestBed.inject(HttpTestingController);
    harness = await RouterTestingHarness.create();
    component = await harness.navigateByUrl('/server-files/1', ServerFiles);
    http.expectOne('/api/servers/1/files').flush({ data: directory });
    harness.fixture.detectChanges();
  });

  afterEach(() => {
    http.verify();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
  });

  it('shows the server path and exposes actions only for files', () => {
    expect(component.directory()).toEqual(directory);
    const text = harness.fixture.nativeElement.textContent as string;
    expect(text).toContain('Production');
    expect(text).toContain('Ubicacion actual');
    expect(text).toContain('/home/ftp-user');
    expect(text).toContain('Descargar');
    expect(text).toContain('Borrar');
    expect(text).toContain('Sin acciones');
    expect(component.formatDate('20261004120000')).not.toBe('20261004120000');
    expect(component.visibleFolders().map((item) => item.node.name)).toEqual(['/', 'assets']);
  });

  it('filters names only within the current directory', () => {
    const element = harness.fixture.nativeElement as HTMLElement;
    const input = element.querySelector<HTMLInputElement>('#file-search')!;
    input.value = 'INDEX';
    input.dispatchEvent(new Event('input'));
    harness.fixture.detectChanges();

    expect(component.visibleEntries().map((entry) => entry.name)).toEqual(['index.html']);
    expect(component.directory()?.entries).toHaveLength(3);
    expect(element.textContent).toContain('index.html');

    input.value = 'missing';
    input.dispatchEvent(new Event('input'));
    harness.fixture.detectChanges();
    expect(component.visibleEntries()).toEqual([]);
    expect(element.textContent).toContain('No hay coincidencias en esta carpeta');
  });

  it('loads a folder tree branch only when it is expanded', () => {
    const element = harness.fixture.nativeElement as HTMLElement;
    const expand = element.querySelector<HTMLButtonElement>('[aria-label="Expandir assets"]');
    expect(expand).not.toBeNull();
    expand!.click();
    const request = http.expectOne((candidate) =>
      candidate.url === '/api/servers/1/files' && candidate.params.get('path') === 'assets');
    request.flush({
      data: {
        ...directory,
        relative_path: 'assets',
        path: '/home/ftp-user/assets',
        parent_relative_path: '',
        entries: [
          { name: 'images', relative_path: 'assets/images', type: 'directory', size: null, modified: null },
        ],
      },
    });
    harness.fixture.detectChanges();

    expect(component.visibleFolders().map((item) => item.node.name)).toEqual(['/', 'assets', 'images']);
  });

  it('navigates into a listed directory using a relative path', async () => {
    component.searchTerm.set('index');
    component.openDirectory(folder);
    await harness.fixture.whenStable();
    harness.fixture.detectChanges();

    const request = http.expectOne((candidate) =>
      candidate.url === '/api/servers/1/files' && candidate.params.get('path') === 'assets');
    request.flush({
      data: {
        ...directory,
        relative_path: 'assets',
        path: '/home/ftp-user/assets',
        parent_relative_path: '',
        entries: [],
      },
    });
    harness.fixture.detectChanges();

    expect(component.currentPath()).toBe('assets');
    expect(component.directory()?.path).toBe('/home/ftp-user/assets');
    expect(component.searchTerm()).toBe('');
    expect(component.visibleFolders().map((item) => item.node.name)).toEqual(['/', 'assets']);
  });

  it('reveals the selected branch when opening a deep-linked folder', async () => {
    await harness.navigateByUrl('/server-files/1?path=assets/images', ServerFiles);
    const request = http.expectOne((candidate) =>
      candidate.url === '/api/servers/1/files' && candidate.params.get('path') === 'assets/images');
    request.flush({
      data: {
        ...directory,
        relative_path: 'assets/images',
        path: '/home/ftp-user/assets/images',
        parent_relative_path: 'assets',
        entries: [],
      },
    });
    harness.fixture.detectChanges();

    expect(component.visibleFolders().map((item) => item.node.name)).toEqual(['/', 'assets', 'images']);
    expect(component.currentPath()).toBe('assets/images');
  });

  it('requires confirmation with the exact remote path before deletion', () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
    component.confirmDelete(file);
    http.expectNone((request) => request.method === 'DELETE');
    expect(confirm).toHaveBeenCalledWith(expect.stringContaining('/home/ftp-user/index.html'));

    confirm.mockReturnValue(true);
    component.confirmDelete(file);
    const request = http.expectOne((candidate) =>
      candidate.url === '/api/servers/1/files' && candidate.method === 'DELETE');
    expect(request.request.body).toEqual({ path: 'index.html' });
    request.flush({ data: { path: '/home/ftp-user/index.html' } });
    http.expectOne('/api/servers/1/files').flush({ data: { ...directory, entries: [] } });

    expect(component.notice()).toContain('/home/ftp-user/index.html');
  });

  it('downloads a selected file as an attachment', () => {
    const createObjectURL = vi.fn().mockReturnValue('blob:remote-file');
    const revokeObjectURL = vi.fn();
    const originalUrl = globalThis.URL;
    const testUrl = class extends originalUrl {};
    Object.defineProperties(testUrl, {
      createObjectURL: { configurable: true, value: createObjectURL },
      revokeObjectURL: { configurable: true, value: revokeObjectURL },
    });
    vi.stubGlobal('URL', testUrl);
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});

    component.download(file);
    const request = http.expectOne((candidate) =>
      candidate.url === '/api/servers/1/files/download' && candidate.params.get('path') === 'index.html');
    expect(request.request.responseType).toBe('blob');
    request.flush(new Blob(['file contents']));

    expect(createObjectURL).toHaveBeenCalledOnce();
    expect(click).toHaveBeenCalledOnce();
    expect(component.notice()).toContain('index.html');
    expect(component.downloadingPath()).toBeNull();
  });

  it('does not allow opening or acting on links as files', () => {
    const link: RemoteFileEntry = directory.entries[2];
    component.openDirectory(link);
    component.download(link);
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    component.confirmDelete(link);

    http.expectNone((request) => request.url.includes('/download') || request.method === 'DELETE');
  });
});
