import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { FileManifest } from './file-api';
import { FileSelection } from './file-selection';
import { vi } from 'vitest';
import { Deploy } from '../deploys/deploy-api';

describe('FileSelection', () => {
  let fixture: ComponentFixture<FileSelection>;
  let component: FileSelection;
  let http: HttpTestingController;
  const manifest: FileManifest = {
    project_id: 1, server_id: 2, source_path: 'C:\\Site\\dist',
    destinations: { backend: '/public_html/site', public: null },
    last_deploy_id: 9, generated_at: '', hash_algorithm: 'sha1',
    summary: { files: 3, changed: 2, bytes: 30, obsolete: 0 },
    obsolete_files: [],
    files: [
      { path: 'index.html', size: 10, hash: 'a', changed: true, ignored: false, remote_path: '/public_html/site/index.html' },
      { path: 'assets/app.js', size: 10, hash: 'b', changed: false, ignored: false, remote_path: '/public_html/site/assets/app.js' },
      { path: 'assets/logo.svg', size: 10, hash: 'c', changed: true, ignored: false, remote_path: '/public_html/site/assets/logo.svg' },
    ],
    tree: [
      { path: 'assets', name: 'assets', kind: 'directory', ignored: false, reason: null, children: [
        { path: 'assets/app.js', name: 'app.js', kind: 'file', ignored: false, reason: null, size: 10, changed: false },
        { path: 'assets/logo.svg', name: 'logo.svg', kind: 'file', ignored: false, reason: null, size: 10, changed: true },
      ] },
      { path: 'index.html', name: 'index.html', kind: 'file', ignored: false, reason: null, size: 10, changed: true },
      { path: '.env', name: '.env', kind: 'file', ignored: true, reason: 'Protegido: variables de entorno.' },
    ],
  };

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [FileSelection], providers: [provideHttpClient(), provideHttpClientTesting()],
    }).compileComponents();
    fixture = TestBed.createComponent(FileSelection);
    component = fixture.componentInstance;
    http = TestBed.inject(HttpTestingController);
    fixture.componentRef.setInput('projectId', 1);
    fixture.componentRef.setInput('serverId', 2);
    fixture.componentRef.setInput('enabled', true);
    fixture.detectChanges();
    vi.useFakeTimers();
  });

  afterEach(() => {
    fixture.destroy();
    http.verify();
    vi.useRealTimers();
  });

  function load(data = manifest): void {
    component.load();
    http.expectOne('/api/projects/1/files?server_id=2').flush({ data });
    http.expectOne('/api/projects/1/deploys/active?server_id=2').flush({ data: null });
    fixture.detectChanges();
  }

  function deploy(status: Deploy['status'] = 'queued'): Deploy {
    return {
      id: 41, project_id: 1, server_id: 2, build_id: 5, project_name: 'Site', project_type: 'angular',
      server_name: 'Production', source_path: 'C:\\Site\\dist', version: '1.0.1', changes: 'Fixes',
      status, progress: status === 'success' ? 100 : 0, duration_ms: null, log: 'Deploy iniciado',
      remote_path: '/public_html/site', public_remote_path: null, delete_obsolete: true,
      version_file_name: status === 'success' ? 'deploy_v1.0.1_20261005_120000.json' : null,
      git_commit: null, started_at: null, finished_at: null, files: [],
    };
  }

  it('loads only on request and defaults to changed files with counts and destinations', () => {
    http.expectNone('/api/projects/1/files?server_id=2');
    load();
    expect([...component.selected()]).toEqual(['index.html', 'assets/logo.svg']);
    expect(component.selectedBytes()).toBe(20);
    const element: HTMLElement = fixture.nativeElement;
    expect(element.textContent).toContain('2 archivos seleccionados');
    expect(element.querySelector('.selection-list')).toBeNull();
    const review = element.querySelector<HTMLDetailsElement>('.panel > details')!;
    review.open = true;
    review.dispatchEvent(new Event('toggle'));
    fixture.detectChanges();
    expect(element.textContent).toContain('/public_html/site/index.html');
    expect(component.loading()).toBe(false);
  });

  it('supports all, none, changed and individual files without HTTP or excluded paths', () => {
    load();
    component.choose('all');
    expect(component.selectedFiles()).toHaveLength(3);
    component.choose('none');
    expect(component.selectedFiles()).toHaveLength(0);
    component.toggle({ paths: ['assets/app.js', '.env', '../secret'], checked: true });
    expect([...component.selected()]).toEqual(['assets/app.js']);
    component.choose('changed');
    component.toggle({ paths: ['index.html'], checked: false });
    expect([...component.selected()]).toEqual(['assets/logo.svg']);
    http.expectNone('/api/projects/1/files?server_id=2');
  });

  it('confirms cleanup explicitly, posts selected files, and polls progress through completion', () => {
    load({
      ...manifest,
      obsolete_files: [{ path: 'old-chunk.js', remote_path: '/public_html/site/old-chunk.js', hash: 'old', size: 8 }],
      summary: { ...manifest.summary, obsolete: 1 },
    });
    const element: HTMLElement = fixture.nativeElement;
    const cleanup = element.querySelector<HTMLInputElement>('#deploy-delete-obsolete')!;
    expect(cleanup.checked).toBe(false);
    expect(cleanup.disabled).toBe(false);
    component.setVersion('1.0.1');
    component.setChanges('Fixes');
    component.setDeleteObsolete(true);
    component.confirmDeploy.set(true);
    expect(component.canDeploy()).toBe(true);

    component.startDeploy();
    const request = http.expectOne('/api/deploys');
    expect(request.request.body).toEqual({
      project_id: 1, server_id: 2, build_id: null, version: '1.0.1', changes: 'Fixes',
      delete_obsolete: true, files: ['index.html', 'assets/logo.svg'],
    });
    request.flush({ data: deploy() });
    expect(component.confirmDeploy()).toBe(false);
    vi.advanceTimersByTime(0);
    http.expectOne('/api/deploys/41').flush({ data: { ...deploy('running'), progress: 45, log: 'Subiendo...' } });
    expect(component.deployActive()).toBe(true);
    expect(component.deploy()?.progress).toBe(45);
    fixture.detectChanges();
    expect(element.querySelector<HTMLButtonElement>('.deploy-panel .primary')?.disabled).toBe(true);

    vi.advanceTimersByTime(1500);
    http.expectOne('/api/deploys/41').flush({ data: deploy('success') });
    expect(component.deployActive()).toBe(false);
    expect(component.deploy()?.version_file_name).toContain('deploy_v1.0.1');
    expect(component.canDeploy()).toBe(false);
    vi.advanceTimersByTime(3000);
    http.expectNone('/api/deploys/41');
  });

  it('allows a cleanup-only deploy when all source files match and obsolete files are tracked', () => {
    const unchanged = {
      ...manifest,
      files: manifest.files.map((file) => ({ ...file, changed: false })),
      obsolete_files: [{ path: 'old-chunk.js', remote_path: '/public_html/site/old-chunk.js', hash: 'old', size: 8 }],
      summary: { ...manifest.summary, changed: 0, obsolete: 1 },
    };
    load(unchanged);
    expect(component.selectedFiles()).toHaveLength(0);
    expect(component.canCleanObsolete()).toBe(true);
    component.setVersion('1.0.2');
    component.setDeleteObsolete(true);
    component.confirmDeploy.set(true);
    expect(component.canDeploy()).toBe(true);
    component.startDeploy();
    const request = http.expectOne('/api/deploys');
    expect(request.request.body.files).toEqual([]);
    request.flush({ data: deploy('success') });
    expect(component.deploy()?.status).toBe('success');
  });

  it('keeps obsolete cleanup disabled until every changed file is selected', async () => {
    load({
      ...manifest,
      obsolete_files: [{ path: 'old-chunk.js', remote_path: '/public_html/site/old-chunk.js', hash: 'old', size: 8 }],
      summary: { ...manifest.summary, obsolete: 1 },
    });
    component.choose('none');
    fixture.detectChanges();
    await fixture.whenStable();
    const cleanup = (fixture.nativeElement as HTMLElement).querySelector<HTMLInputElement>('#deploy-delete-obsolete')!;
    expect(component.canCleanObsolete()).toBe(false);
    expect(cleanup.disabled).toBe(true);

    component.choose('changed');
    fixture.detectChanges();
    await fixture.whenStable();
    expect(component.canCleanObsolete()).toBe(true);
    expect(cleanup.disabled).toBe(false);
  });

  it('recovers and resumes polling an active deploy when the file manifest is loaded again', () => {
    component.load();
    http.expectOne('/api/projects/1/files?server_id=2').flush({ data: manifest });
    http.expectOne('/api/projects/1/deploys/active?server_id=2').flush({ data: deploy('running') });
    expect(component.deployActive()).toBe(true);
    vi.advanceTimersByTime(0);
    http.expectOne('/api/deploys/41').flush({ data: deploy('success') });
    expect(component.deployActive()).toBe(false);
    expect(component.deploy()?.status).toBe('success');
  });

  it('folder checkboxes show mixed state and select/deselect all publicable descendants', () => {
    load();
    const element: HTMLElement = fixture.nativeElement;
    const folder = element.querySelector<HTMLInputElement>('input[aria-label="Seleccionar carpeta assets"]')!;
    expect(folder.indeterminate).toBe(true);
    expect(folder.checked).toBe(false);
    folder.checked = true;
    folder.dispatchEvent(new Event('change', { bubbles: true }));
    fixture.detectChanges();
    expect(component.selected().has('assets/app.js')).toBe(true);
    expect(folder.indeterminate).toBe(false);
    expect(folder.checked).toBe(true);
    folder.checked = false;
    folder.dispatchEvent(new Event('change', { bubbles: true }));
    fixture.detectChanges();
    expect([...component.selected()]).toEqual(['index.html']);
  });

  it('renders nested files only when expanding a folder and permits individual checkbox changes', () => {
    load();
    const element: HTMLElement = fixture.nativeElement;
    expect(element.querySelector('input[aria-label="Seleccionar archivo assets/app.js"]')).toBeNull();
    const details = element.querySelector<HTMLDetailsElement>('app-file-tree details')!;
    details.open = true;
    details.dispatchEvent(new Event('toggle'));
    fixture.detectChanges();
    const file = element.querySelector<HTMLInputElement>('input[aria-label="Seleccionar archivo assets/app.js"]')!;
    expect(file.checked).toBe(false);
    file.checked = true;
    file.dispatchEvent(new Event('change', { bubbles: true }));
    fixture.detectChanges();
    expect(component.selected().has('assets/app.js')).toBe(true);
    details.open = false;
    details.dispatchEvent(new Event('toggle'));
    fixture.detectChanges();
    expect(element.querySelector('input[aria-label="Seleccionar archivo assets/app.js"]')).toBeNull();
    expect(component.selected().has('assets/app.js')).toBe(true);
  });

  it('hides excluded files by default and never enables their checkbox', () => {
    load();
    const element: HTMLElement = fixture.nativeElement;
    expect(element.querySelector('input[aria-label="Seleccionar archivo .env"]')).toBeNull();
    component.showIgnored.set(true);
    fixture.detectChanges();
    expect(element.querySelector<HTMLInputElement>('input[aria-label="Seleccionar archivo .env"]')?.disabled).toBe(true);
    expect(component.ignoredCount()).toBe(1);
    expect(element.textContent).toContain('Protegido: variables de entorno.');
  });

  it('cancels requests and invalidates selection when server/project/build changes', () => {
    load();
    fixture.componentRef.setInput('serverId', 3);
    fixture.detectChanges();
    expect(component.manifest()).toBeNull();
    expect(component.selected().size).toBe(0);
    component.load();
    const pending = http.expectOne('/api/projects/1/files?server_id=3');
    fixture.componentRef.setInput('projectId', 4);
    fixture.detectChanges();
    expect(pending.cancelled).toBe(true);
    expect(component.loading()).toBe(false);
    fixture.componentRef.setInput('enabled', false);
    fixture.detectChanges();
    component.load();
    http.expectNone('/api/projects/4/files?server_id=3');
  });

  it('reports API errors, clears stale selections on reload and permits retry', () => {
    load();
    component.load();
    expect(component.manifest()).toBeNull();
    expect(component.selected().size).toBe(0);
    http.expectOne('/api/projects/1/files?server_id=2').flush(
      { errors: { files: ['La carpeta no existe.'] } }, { status: 422, statusText: 'Invalid' },
    );
    fixture.detectChanges();
    expect(component.error()).toContain('La carpeta no existe.');
    load();
    expect(component.error()).toBe('');
    expect(component.selectedFiles()).toHaveLength(2);
  });

  it('handles first deploy and empty origins truthfully and requires a server', () => {
    load({ ...manifest, files: [], tree: [], obsolete_files: [], last_deploy_id: null, summary: { files: 0, changed: 0, bytes: 0, obsolete: 0 } });
    expect(component.selected().size).toBe(0);
    expect((fixture.nativeElement as HTMLElement).textContent).toContain('No hay archivos publicables');
    fixture.componentRef.setInput('serverId', 0);
    fixture.detectChanges();
    component.load();
    expect((fixture.nativeElement as HTMLElement).textContent).toContain('Elige un servidor asociado');
    http.expectNone('/api/projects/1/files?server_id=0');
  });

  it('revision changes and destruction discard/cancel pending work', () => {
    load();
    fixture.componentRef.setInput('revision', 1);
    fixture.detectChanges();
    expect(component.selected().size).toBe(0);
    component.load();
    const request = http.expectOne('/api/projects/1/files?server_id=2');
    fixture.destroy();
    expect(request.cancelled).toBe(true);
  });
});
