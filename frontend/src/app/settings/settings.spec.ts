import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { Settings } from './settings';

describe('Settings', () => {
  let component: Settings;
  let http: HttpTestingController;
  const settings = { projects_root: 'C:\\Angular', npx_path: 'npx.cmd', ftp_retries: 3, ftp_retry_delay_ms: 1000 };

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [Settings],
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])],
    }).compileComponents();
    const fixture = TestBed.createComponent(Settings);
    component = fixture.componentInstance;
    http = TestBed.inject(HttpTestingController);
    fixture.detectChanges();
    http.expectOne('/api/settings').flush({ data: settings });
  });

  afterEach(() => http.verify());

  it('loads and saves settings with typed numbers', () => {
    expect(component.form.getRawValue()).toEqual({ ...settings, excluded_folders_text: 'mobile\ndesktop\nescritorio', hide_non_web_projects: true });
    component.form.controls.ftp_retries.setValue(5);
    component.save();
    const request = http.expectOne('/api/settings');
    expect(request.request.method).toBe('PUT');
    expect(request.request.body.ftp_retries).toBe(5);
    request.flush({ data: { ...settings, ftp_retries: 5 } });
    expect(component.notice()).toContain('Configuracion guardada');
    expect(component.saving()).toBe(false);
  });

  it('rejects invalid numeric limits before sending', () => {
    component.form.controls.ftp_retries.setValue(11);
    component.save();
    http.expectNone('/api/settings');
    expect(component.error()).toContain('Revisa');
    component.form.controls.ftp_retries.setValue(3);
    component.form.controls.ftp_retry_delay_ms.setValue(-1);
    component.save();
    http.expectNone('/api/settings');
  });

  it('retains input and displays errors when the directory does not exist', () => {
    component.form.controls.projects_root.setValue('C:\\missing');
    component.save();
    http.expectOne('/api/settings').flush(
      { errors: { projects_root: ['La carpeta no existe.'] } },
      { status: 422, statusText: 'Unprocessable Entity' },
    );
    expect(component.error()).toBe('La carpeta no existe.');
    expect(component.form.controls.projects_root.value).toBe('C:\\missing');
    expect(component.notice()).toBe('');
  });

  it('reports failed loads and releases the loading state', () => {
    component.load();
    http.expectOne('/api/settings').error(new ProgressEvent('error'));
    expect(component.error()).toContain('Laravel');
    expect(component.loading()).toBe(false);
  });

  it('saves configurable folder exclusions and platform detection', () => {
    component.form.controls.excluded_folders_text.setValue('mobile\n  desktop  \n\n');
    component.form.controls.hide_non_web_projects.setValue(false);
    component.save();
    const request = http.expectOne('/api/settings');
    expect(request.request.body.excluded_project_folders).toEqual(['mobile', 'desktop']);
    expect(request.request.body.hide_non_web_projects).toBe(false);
    expect(request.request.body).not.toHaveProperty('excluded_folders_text');
    request.flush({ data: { ...settings, excluded_project_folders: ['mobile', 'desktop'], hide_non_web_projects: false } });
  });
});
