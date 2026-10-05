import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { Servers } from './servers';
import { Server } from './server-api';

describe('Servers', () => {
  let fixture: ComponentFixture<Servers>;
  let component: Servers;
  let http: HttpTestingController;
  const server: Server = {
    id: 1, name: 'Produccion', host: 'ftp.example.test', port: 21, username: 'user',
    use_ftps: true, passive: true, remote_path: '/public_html',
    created_at: '2026-10-04T00:00:00Z', updated_at: '2026-10-04T00:00:00Z',
  };

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [Servers],
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])],
    }).compileComponents();
    fixture = TestBed.createComponent(Servers);
    component = fixture.componentInstance;
    http = TestBed.inject(HttpTestingController);
    fixture.detectChanges();
    http.expectOne('/api/servers').flush({ data: [server] });
    fixture.detectChanges();
  });

  afterEach(() => http.verify());

  it('lists servers without password fields and recommends FTPS', () => {
    const element: HTMLElement = fixture.nativeElement;
    expect(element.querySelector('.server h3')?.textContent).toBe('Produccion');
    expect(component.form.controls.use_ftps.value).toBe(true);
    expect(component.form.controls.passive.value).toBe(true);
    expect(component.form.controls.password.value).toBe('');
  });

  it('opens the dedicated file explorer in a new protected tab', () => {
    const link = (fixture.nativeElement as HTMLElement).querySelector<HTMLAnchorElement>('.server-explorer');
    expect(link?.getAttribute('href')).toContain('/server-files/1');
    expect(link?.target).toBe('_blank');
    expect(link?.rel).toContain('noopener');
    expect(link?.rel).toContain('noreferrer');
  });

  it('creates a server and clears its password after saving', () => {
    component.form.patchValue({ ...server, password: 'secret' });
    component.save();
    const request = http.expectOne('/api/servers');
    expect(request.request.method).toBe('POST');
    expect(request.request.body.password).toBe('secret');
    request.flush({ data: { ...server, id: 2, name: 'Segundo' } });
    expect(component.servers().length).toBe(2);
    expect(component.form.controls.password.value).toBe('');
    expect(component.notice()).toBe('Servidor creado.');
    expect(component.saving()).toBe(false);
  });

  it('preserves the existing password when editing with an empty field', () => {
    component.edit(server);
    component.form.controls.name.setValue('Editado');
    component.save();
    const request = http.expectOne('/api/servers/1');
    expect(request.request.method).toBe('PUT');
    expect(request.request.body).not.toHaveProperty('password');
    request.flush({ data: { ...server, name: 'Editado' } });
    expect(component.servers()[0].name).toBe('Editado');
    expect(component.editingId()).toBeNull();
  });

  it('sends a new password when explicitly entered during editing', () => {
    component.edit(server);
    component.form.controls.password.setValue('replacement');
    component.save();
    const request = http.expectOne('/api/servers/1');
    expect(request.request.body.password).toBe('replacement');
    request.flush({ data: server });
    expect(component.form.controls.password.value).toBe('');
  });

  it('does not send invalid forms', () => {
    component.save();
    http.expectNone('/api/servers');
    expect(component.error()).toContain('Revisa');
    component.form.patchValue({ ...server, password: 'secret', port: 65536 });
    component.save();
    http.expectNone('/api/servers');
    component.form.controls.port.setValue(21);
    component.form.controls.remote_path.setValue('C:\\invalid');
    component.save();
    http.expectNone('/api/servers');
  });

  it('shows backend validation errors and clears the submitted password', () => {
    component.form.patchValue({ ...server, password: 'secret' });
    component.save();
    http.expectOne('/api/servers').flush(
      { errors: { host: ['Host invalido.'] } },
      { status: 422, statusText: 'Unprocessable Entity' },
    );
    expect(component.error()).toBe('Host invalido.');
    expect(component.form.controls.password.value).toBe('');
    expect(component.saving()).toBe(false);
  });

  it('tests the saved connection and shows a remote listing', () => {
    component.test(server);
    expect(component.busy()).toBe(true);
    const request = http.expectOne('/api/servers/1/test');
    expect(request.request.method).toBe('POST');
    request.flush({ data: { message: 'OK', protocol: 'FTPS', remote_path: '/public_html', entries: ['index.html'] } });
    expect(component.result()?.connection.entries).toEqual(['index.html']);
    expect(component.testingId()).toBeNull();
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).textContent).toContain('conexion correcta');
  });

  it('shows connection failures and releases the busy state', () => {
    component.test(server);
    http.expectOne('/api/servers/1/test').flush(
      { message: 'No se pudo conectar.' }, { status: 502, statusText: 'Bad Gateway' },
    );
    expect(component.error()).toBe('No se pudo conectar.');
    expect(component.result()).toBeNull();
    expect(component.busy()).toBe(false);
  });

  it('requires confirmation before deleting and resets an edited server', () => {
    component.edit(server);
    component.requestDelete(server);
    http.expectNone('/api/servers/1');
    expect(component.deleteCandidate()).toEqual(server);
    component.remove();
    const request = http.expectOne('/api/servers/1');
    expect(request.request.method).toBe('DELETE');
    request.flush(null, { status: 204, statusText: 'No Content' });
    expect(component.servers()).toEqual([]);
    expect(component.deleteCandidate()).toBeNull();
    expect(component.editingId()).toBeNull();
  });

  it('retains the server and displays errors when deletion fails', () => {
    component.requestDelete(server);
    component.remove();
    http.expectOne('/api/servers/1').flush(
      { message: 'No se pudo eliminar.' }, { status: 500, statusText: 'Server Error' },
    );
    expect(component.servers()).toEqual([server]);
    expect(component.error()).toBe('No se pudo eliminar.');
    expect(component.busy()).toBe(false);
  });

  it('reports an unreachable backend instead of silently returning an empty list', () => {
    component.load();
    http.expectOne('/api/servers').error(new ProgressEvent('error'));
    expect(component.error()).toContain('Laravel');
    expect(component.servers()).toEqual([server]);
    expect(component.loading()).toBe(false);
  });
});
