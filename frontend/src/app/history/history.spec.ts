import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { Deploy, DeploySummary } from '../deploys/deploy-api';
import { Project } from '../projects/project-api';
import { History } from './history';

describe('History', () => {
  let fixture: ComponentFixture<History>;
  let component: History;
  let http: HttpTestingController;

  const project: Project = {
    id: 1, name: 'Angular site', type: 'angular',
    local_path: 'C:\\Projects\\site', angular_project: 'site',
    build_output_path: 'C:\\Projects\\site\\dist', build_command: 'npx ng build',
    ignore_patterns: [], current_version: '1.2.3', servers: [], created_at: '', updated_at: '',
  };
  const summary: DeploySummary = {
    id: 7, project_id: 1, server_id: 2, build_id: null,
    project_name: 'Angular site', project_type: 'angular', server_name: 'Production',
    source_path: null, version: '1.2.3', changes: 'Ajuste visual',
    status: 'success', progress: 100, duration_ms: 8000, remote_path: '/public_html/site',
    public_remote_path: null, delete_obsolete: true, version_file_name: 'version-1.2.3.json',
    git_commit: null, started_at: '2026-01-01T12:00:00Z', finished_at: '2026-01-01T12:00:08Z',
    created_at: '2026-01-01T12:00:00Z', files_count: 2, uploaded_files_count: 1,
    deleted_files_count: 1, absent_files_count: 0,
  };
  const detail: Deploy = {
    ...summary, log: 'Deploy completo', files: [
      {
        id: 1, deploy_id: 7, relative_path: 'index.html',
        remote_path: '/public_html/site/index.html', local_path: null,
        hash: 'abc123', size: 120, status: 'uploaded', backup_path: null, error: null,
      },
      {
        id: 2, deploy_id: 7, relative_path: 'old.js',
        remote_path: '/public_html/site/old.js', local_path: null,
        hash: 'def456', size: 45, status: 'deleted', backup_path: 'C:\\backups\\old.js', error: null,
      },
    ],
  };

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [History],
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])],
    }).compileComponents();
    fixture = TestBed.createComponent(History);
    component = fixture.componentInstance;
    http = TestBed.inject(HttpTestingController);
    fixture.detectChanges();
    http.expectOne('/api/projects?include_hidden=1').flush({ data: [project] });
    const listRequest = http.expectOne((request) => request.url === '/api/deploys');
    expect(listRequest.request.params.get('page')).toBe('1');
    expect(listRequest.request.params.get('per_page')).toBe('20');
    listRequest.flush({
      data: [summary],
      meta: { current_page: 1, last_page: 2, per_page: 20, total: 21 },
    });
    fixture.detectChanges();
  });

  afterEach(() => http.verify());

  it('renders the history summary and loads selected detail with files and log', () => {
    const element = fixture.nativeElement as HTMLElement;
    expect(element.textContent).toContain('Historial de deploys');
    expect(element.textContent).toContain('Angular site · v1.2.3');
    expect(element.textContent).toContain('1 subidos');
    expect(element.textContent).toContain('Página 1 de 2');

    element.querySelector<HTMLButtonElement>('.history-item')!.click();
    const request = http.expectOne('/api/deploys/7');
    request.flush({ data: detail });
    fixture.detectChanges();

    expect(element.textContent).toContain('Deploy #7 · v1.2.3');
    expect(element.textContent).toContain('index.html');
    expect(element.textContent).toContain('old.js');
    expect(element.textContent).toContain('C:\\backups\\old.js');
    expect(element.textContent).toContain('Deploy completo');
  });

  it('filters the history by project and requests the next page', () => {
    const element = fixture.nativeElement as HTMLElement;
    const select = element.querySelector<HTMLSelectElement>('#history-project')!;
    select.value = '1';
    select.dispatchEvent(new Event('change'));
    const filtered = http.expectOne((request) => request.url === '/api/deploys' && request.params.get('project_id') === '1');
    expect(filtered.request.params.get('page')).toBe('1');
    filtered.flush({ data: [summary], meta: { current_page: 1, last_page: 2, per_page: 20, total: 21 } });
    fixture.detectChanges();

    component.nextPage();
    const next = http.expectOne((request) => request.url === '/api/deploys' && request.params.get('page') === '2');
    expect(next.request.params.get('project_id')).toBe('1');
    next.flush({ data: [], meta: { current_page: 2, last_page: 2, per_page: 20, total: 21 } });
    expect(component.meta().current_page).toBe(2);
  });
});
