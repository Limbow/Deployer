import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { Project, ProjectCandidate } from './project-api';
import { Projects } from './projects';

describe('Projects', () => {
  let fixture: ComponentFixture<Projects>;
  let component: Projects;
  let http: HttpTestingController;
  const candidate: ProjectCandidate = {
    type: 'angular',
    name: 'site', angular_project: 'site', local_path: 'C:\\Angular\\site',
    build_output_path: 'C:\\Angular\\site\\dist\\site\\browser',
    build_command: 'npx ng build site --configuration production',
    builder: '@angular/build:application', current_version: '1.2.3',
    registered_id: null, registered_angular_project: null,
  };
  const project: Project = {
    type: 'angular',
    id: 1, name: candidate.name, angular_project: candidate.angular_project,
    local_path: candidate.local_path, build_output_path: candidate.build_output_path,
    build_command: candidate.build_command, current_version: candidate.current_version,
    ignore_patterns: ['*.map'], servers: [], created_at: '', updated_at: '',
  };

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [Projects],
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])],
    }).compileComponents();
    fixture = TestBed.createComponent(Projects);
    component = fixture.componentInstance;
    http = TestBed.inject(HttpTestingController);
    fixture.detectChanges();
    http.expectOne('/api/projects').flush({ data: [] });
    http.expectOne('/api/servers').flush({ data: [{
      id: 2, name: 'Produccion', host: 'ftp.example.test', port: 21, username: 'user',
      use_ftps: true, passive: true, remote_path: '/public_html', created_at: '', updated_at: '',
    }] });
    http.expectOne('/api/settings').flush({ data: {
      projects_root: 'C:\\Angular', npx_path: 'npx.cmd', ftp_retries: 3, ftp_retry_delay_ms: 1000,
    } });
    fixture.detectChanges();
  });

  afterEach(() => http.verify());

  it('filters both lists instantly with the three buttons without changing source data or making requests', () => {
    const laravelCandidate: ProjectCandidate = { ...candidate, type: 'laravel', name: 'api', local_path: 'C:\\Angular\\api', angular_project: null, build_command: null };
    const laravelProject: Project = { ...project, id: 2, type: 'laravel', name: 'api', local_path: laravelCandidate.local_path, angular_project: null, build_command: null };
    component.projects.set([project, laravelProject]);
    component.scanResult.set({ projects: [candidate, laravelCandidate], issues: [] });
    fixture.detectChanges();
    const element: HTMLElement = fixture.nativeElement;
    const buttons = element.querySelectorAll<HTMLButtonElement>('.type-filters button');
    expect(buttons[2].getAttribute('aria-pressed')).toBe('true');
    expect(element.querySelectorAll('.server')).toHaveLength(4);

    buttons[0].click();
    fixture.detectChanges();
    expect(component.filteredProjects()).toEqual([laravelProject]);
    expect(component.filteredCandidates()).toEqual([laravelCandidate]);
    expect(buttons[0].getAttribute('aria-pressed')).toBe('true');
    expect(element.querySelectorAll('.server')).toHaveLength(2);

    buttons[1].click();
    fixture.detectChanges();
    expect(component.filteredProjects()).toEqual([project]);
    expect(component.filteredCandidates()).toEqual([candidate]);

    buttons[2].click();
    fixture.detectChanges();
    expect(component.filteredProjects()).toHaveLength(2);
    expect(component.filteredCandidates()).toHaveLength(2);
    expect(component.projects()).toEqual([project, laravelProject]);
    expect(component.scanResult()?.projects).toEqual([candidate, laravelCandidate]);
    http.expectNone('/api/projects');
    http.expectNone('/api/projects/scan');
  });

  it('shows explicit empty filter states and keeps the active filter after rescanning', () => {
    component.projects.set([project]);
    component.scanResult.set({ projects: [candidate], issues: [] });
    component.typeFilter.set('laravel');
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).textContent).toContain('No hay proyectos detectados que coincidan');
    expect((fixture.nativeElement as HTMLElement).textContent).toContain('No hay proyectos guardados que coincidan');
    component.scan();
    http.expectOne('/api/projects/scan').flush({ data: { projects: [candidate], issues: [] } });
    expect(component.typeFilter()).toBe('laravel');
    expect(component.filteredCandidates()).toEqual([]);
    component.typeFilter.set('all');
    expect(component.filteredCandidates()).toEqual([candidate]);
  });

  it('loads projects, servers and root without automatically scanning or importing', () => {
    expect(component.ready()).toBe(true);
    expect(component.root()).toBe('C:\\Angular');
    expect(component.scanResult()).toBeNull();
    http.expectNone('/api/projects/scan');
    expect(component.servers()[0].id).toBe(2);
  });

  it('shows detected candidates and partial scan issues without importing', () => {
    component.scan();
    http.expectOne('/api/projects/scan').flush({
      data: { projects: [candidate], issues: [{ local_path: 'C:\\Angular\\broken', message: 'JSON invalido.' }] },
    });
    expect(component.projects()).toEqual([]);
    expect(component.scanResult()?.projects).toEqual([candidate]);
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).textContent).toContain('Problemas detectados');
  });

  it('imports a candidate with ignore patterns and an optional server association', () => {
    component.scanResult.set({ projects: [candidate], issues: [] });
    component.configure(candidate);
    component.addServer();
    component.form.controls.servers.at(0).patchValue({ server_id: 2 });
    component.form.controls.ignore_patterns_text.setValue('*.map\n\n  *.txt  ');
    component.save();
    const request = http.expectOne('/api/projects');
    expect(request.request.method).toBe('POST');
    expect(request.request.body.ignore_patterns).toEqual(['*.map', '*.txt']);
    expect(request.request.body.servers).toEqual([{ server_id: 2, label: 'production', remote_path_override: null, public_remote_path: null }]);
    expect(request.request.body.build_output_path).toBe(candidate.build_output_path);
    request.flush({ data: project });
    expect(component.projects()).toEqual([project]);
    expect(component.scanResult()?.projects[0].registered_id).toBe(1);
    expect(component.formOpen()).toBe(false);
  });

  it('does not reopen a candidate belonging to a registered workspace', () => {
    component.configure({ ...candidate, registered_id: 1 });
    expect(component.formOpen()).toBe(false);
  });

  it('edits saved values and correctly sends server pivot metadata', () => {
    component.edit({
      ...project, servers: [{
        ...component.servers()[0],
        pivot: { label: 'staging', remote_path_override: '/staging' },
      }],
    });
    component.form.controls.name.setValue('Custom');
    component.form.controls.build_output_path.setValue('dist/custom');
    component.save();
    const request = http.expectOne('/api/projects/1');
    expect(request.request.method).toBe('PUT');
    expect(request.request.body.servers).toEqual([{ server_id: 2, label: 'staging', remote_path_override: '/staging', public_remote_path: null }]);
    expect(request.request.body.build_output_path).toBe('dist/custom');
    request.flush({ data: { ...project, name: 'Custom' } });
    expect(component.projects()[0].name).toBe('Custom');
  });

  it('blocks invalid forms and retains user edits after validation errors', () => {
    component.configure(candidate);
    component.form.controls.name.setValue('');
    component.save();
    http.expectNone('/api/projects');
    expect(component.error()).toContain('Revisa');
    component.form.controls.name.setValue('Edited name');
    component.save();
    http.expectOne('/api/projects').flush(
      { errors: { local_path: ['Esta carpeta ya esta registrada.'] } },
      { status: 422, statusText: 'Unprocessable Entity' },
    );
    expect(component.error()).toBe('Esta carpeta ya esta registrada.');
    expect(component.form.controls.name.value).toBe('Edited name');
    expect(component.formOpen()).toBe(true);
    expect(component.busy()).toBe(false);
  });

  it('removes only after confirmation and makes a deleted candidate available again', () => {
    component.projects.set([project]);
    component.scanResult.set({ projects: [{ ...candidate, registered_id: 1 }], issues: [] });
    component.requestDelete(project);
    http.expectNone('/api/projects/1');
    component.remove();
    const request = http.expectOne('/api/projects/1');
    expect(request.request.method).toBe('DELETE');
    request.flush(null, { status: 204, statusText: 'No Content' });
    expect(component.projects()).toEqual([]);
    expect(component.scanResult()?.projects[0].registered_id).toBeNull();
    expect(component.notice()).toContain('Los archivos locales no se modificaron');
  });

  it('clears stale scan results on failures and reports the error explicitly', () => {
    component.scanResult.set({ projects: [candidate], issues: [] });
    component.scan();
    http.expectOne('/api/projects/scan').flush(
      { errors: { projects_root: ['La carpeta no existe.'] } },
      { status: 422, statusText: 'Unprocessable Entity' },
    );
    expect(component.scanResult()).toBeNull();
    expect(component.error()).toBe('La carpeta no existe.');
    expect(component.busy()).toBe(false);
  });

  it('can remove and cancel associations without saving', () => {
    component.configure(candidate);
    component.addServer();
    expect(component.form.controls.servers.length).toBe(1);
    component.removeServer(0);
    expect(component.form.controls.servers.length).toBe(0);
    component.cancel();
    expect(component.formOpen()).toBe(false);
    expect(component.editingId()).toBeNull();
    http.expectNone('/api/projects');
  });

  it('configures Laravel with no Angular application or mandatory build and separate public destination', () => {
    component.configure({
      ...candidate, type: 'laravel', name: 'api', angular_project: null,
      build_command: null, build_output_path: candidate.local_path,
    });
    component.addServer();
    component.form.controls.servers.at(0).patchValue({
      server_id: 2, remote_path_override: '/api', public_remote_path: '/public_html/api',
    });
    component.save();
    const request = http.expectOne('/api/projects');
    expect(request.request.body.type).toBe('laravel');
    expect(request.request.body.angular_project).toBeNull();
    expect(request.request.body.build_command).toBeNull();
    expect(request.request.body.ignore_patterns).toContain('.env');
    expect(request.request.body.servers[0].public_remote_path).toBe('/public_html/api');
    request.flush({ data: { ...project, type: 'laravel', angular_project: null, build_command: null } });
  });

  it('persists hiding without deleting a record and lets users show hidden projects', () => {
    component.projects.set([project]);
    component.scanResult.set({ projects: [candidate], issues: [] });
    component.setVisibility(candidate.local_path, true);
    const request = http.expectOne('/api/projects/visibility');
    expect(request.request.body).toEqual({ local_path: candidate.local_path, hidden: true });
    request.flush({ data: { local_path: candidate.local_path, hidden: true } });
    expect(component.projects()).toEqual([]);
    expect(component.scanResult()?.projects).toEqual([]);
    expect(component.scanResult()?.hidden_count).toBe(1);
    component.toggleHidden();
    http.expectOne('/api/projects?include_hidden=1').flush({ data: [{ ...project, hidden_reason: 'Ocultado manualmente' }] });
    http.expectOne('/api/servers').flush({ data: [] });
    http.expectOne('/api/settings').flush({ data: { projects_root: 'C:\\Angular' } });
    http.expectOne('/api/projects/scan?include_hidden=1').flush({ data: { projects: [{ ...candidate, hidden_reason: 'Ocultado manualmente' }], issues: [] } });
    expect(component.projects()[0].hidden_reason).toBe('Ocultado manualmente');
    component.setVisibility(candidate.local_path, false);
    http.expectOne('/api/projects/visibility').flush({ data: { local_path: candidate.local_path, hidden: false } });
    expect(component.projects()[0].hidden_reason).toBeNull();
  });

  it('requires explicit confirmation before incrementing and updates the saved project version', () => {
    component.projects.set([project]);
    fixture.detectChanges();

    const element = fixture.nativeElement as HTMLElement;
    const bumpButton = Array.from(element.querySelectorAll<HTMLButtonElement>('.actions button'))
      .find((button) => button.textContent?.includes('Subir versión'));
    expect(bumpButton).toBeTruthy();
    bumpButton?.click();
    fixture.detectChanges();
    expect((element.querySelector('.version-confirm') as HTMLElement).textContent).toContain('No se ejecutarán scripts');
    http.expectNone('/api/projects/1/bump-version');

    const select = element.querySelector<HTMLSelectElement>('.version-confirm select');
    expect(select).toBeTruthy();
    select!.value = 'minor';
    select!.dispatchEvent(new Event('change'));
    fixture.detectChanges();
    const confirm = Array.from(element.querySelectorAll<HTMLButtonElement>('.version-confirm button'))
      .find((button) => button.textContent?.includes('Confirmar minor'));
    confirm?.click();

    const request = http.expectOne('/api/projects/1/bump-version');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ type: 'minor' });
    request.flush({ data: { project: { ...project, current_version: '1.3.0' }, updated_files: ['package.json', 'package-lock.json'] } });
    expect(component.projects()[0].current_version).toBe('1.3.0');
    expect(component.notice()).toContain('package.json, package-lock.json');
    expect(component.versionBumpCandidate()).toBeNull();
  });
});
