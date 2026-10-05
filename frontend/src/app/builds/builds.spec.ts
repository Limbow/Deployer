import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { vi } from 'vitest';
import { Project } from '../projects/project-api';
import { Build } from './build-api';
import { Builds } from './builds';

describe('Builds', () => {
  let fixture: ComponentFixture<Builds>;
  let component: Builds;
  let http: HttpTestingController;
  const project: Project = {
    id: 1, name: 'Site', type: 'angular', angular_project: 'site', local_path: 'C:\\Site',
    build_command: 'npx ng build site', build_output_path: 'C:\\Site\\dist',
    ignore_patterns: [], servers: [], current_version: '1.0.0', created_at: '', updated_at: '',
  };
  const build: Build = {
    id: 5, project_id: 1, status: 'queued', command: project.build_command!,
    local_path: project.local_path, build_output_path: project.build_output_path, log: '',
    duration_ms: null, exit_code: null, started_at: null, finished_at: null,
  };

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [Builds], providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])],
    }).compileComponents();
    vi.useFakeTimers();
    fixture = TestBed.createComponent(Builds);
    component = fixture.componentInstance;
    http = TestBed.inject(HttpTestingController);
    fixture.detectChanges();
    http.expectOne('/api/projects').flush({ data: [project, { ...project, id: 2, type: 'laravel', name: 'API', build_command: null }] });
  });

  afterEach(() => {
    fixture.destroy();
    http.verify();
    vi.useRealTimers();
  });

  function select(id = 1, latest: Build | null = null): void {
    component.selectProject(id);
    http.expectOne(`/api/projects/${id}/builds/latest`).flush({ data: latest });
  }

  it('requires confirmation, queues once, streams status/log and stops after success', () => {
    select();
    component.start();
    http.expectNone('/api/projects/1/build');
    component.confirmed.set(true);
    component.start();
    component.start();
    http.expectOne('/api/projects/1/build').flush({ data: build });
    vi.advanceTimersByTime(0);
    http.expectOne('/api/builds/5').flush({ data: { ...build, status: 'running', log: 'Compilando...' } });
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).textContent).toContain('Compilando...');
    component.selectProject(2);
    expect(component.projectId()).toBe(1);
    vi.advanceTimersByTime(1500);
    http.expectOne('/api/builds/5').flush({ data: { ...build, status: 'success', log: 'Listo', duration_ms: 2500, exit_code: 0 } });
    vi.advanceTimersByTime(4500);
    http.expectNone('/api/builds/5');
    expect(component.active()).toBe(false);
    expect(component.fileReady()).toBe(true);
  });

  it('recovers an existing queued build and never overlaps polling requests', () => {
    select(1, build);
    vi.advanceTimersByTime(0);
    const request = http.expectOne('/api/builds/5');
    vi.advanceTimersByTime(4500);
    http.expectNone('/api/builds/5');
    request.flush({ data: { ...build, status: 'failed', log: 'Compile error' } });
    expect(component.build()?.status).toBe('failed');
    expect(component.fileReady()).toBe(false);
    vi.advanceTimersByTime(1500);
    http.expectNone('/api/builds/5');
  });

  it('shows polling errors explicitly and can resume without restarting the job', () => {
    select(1, build);
    vi.advanceTimersByTime(0);
    http.expectOne('/api/builds/5').flush({ message: 'Unavailable' }, { status: 503, statusText: 'Unavailable' });
    expect(component.pollFailed()).toBe(true);
    expect(component.error()).toContain('puede seguir ejecutandose');
    component.watch();
    vi.advanceTimersByTime(0);
    http.expectOne('/api/builds/5').flush({ data: { ...build, status: 'success' } });
    http.expectNone('/api/projects/1/build');
  });

  it('stops polling when destroyed', () => {
    select(1, build);
    vi.advanceTimersByTime(0);
    http.expectOne('/api/builds/5').flush({ data: build });
    fixture.destroy();
    vi.advanceTimersByTime(3000);
    http.expectNone('/api/builds/5');
  });

  it('skips Laravel without faking a build or making a POST', () => {
    select(2);
    fixture.detectChanges();
    const element: HTMLElement = fixture.nativeElement;
    expect(element.textContent).toContain('Continuar sin build');
    component.skip();
    expect(component.notice()).toContain('No se compilo ni se subio');
    expect(component.build()).toBeNull();
    expect(component.fileReady()).toBe(true);
    http.expectNone('/api/projects/2/build');
  });

  it('recovers the latest build after an ambiguous start failure', () => {
    select();
    component.confirmed.set(true);
    component.start();
    http.expectOne('/api/projects/1/build').flush({ message: 'Ya hay un build' }, { status: 409, statusText: 'Conflict' });
    expect(component.error()).toContain('Ya hay un build');
    http.expectOne('/api/projects/1/builds/latest').flush({ data: build });
    vi.advanceTimersByTime(0);
    http.expectOne('/api/builds/5').flush({ data: { ...build, status: 'running' } });
    expect(component.active()).toBe(true);
  });

  it('allows recovered successful builds and invalidates files when starting another build', () => {
    select(1, { ...build, status: 'success' });
    expect(component.fileReady()).toBe(true);
    component.confirmed.set(true);
    component.start();
    expect(component.fileReady()).toBe(false);
    http.expectOne('/api/projects/1/build').flush({ data: build });
    vi.advanceTimersByTime(0);
    http.expectOne('/api/builds/5').flush({ data: { ...build, status: 'failed' } });
    expect(component.fileReady()).toBe(false);
    component.skip();
    expect(component.fileReady()).toBe(true);
  });
});
