import { Component, computed, input, output, signal } from '@angular/core';
import { SelectableNode } from './file-api';

@Component({
  selector: 'app-file-tree',
  template: `
    <ul>
      @for (row of rows(); track row.node.path) {
        <li>
          @if (row.node.kind === 'directory' && !row.node.ignored) {
            <details (toggle)="setOpen($event, row.node.path)">
              <summary>
                <input type="checkbox" [attr.aria-label]="'Seleccionar carpeta ' + row.node.path"
                  [checked]="row.checked" [indeterminate]="row.partial" [disabled]="disabled() || row.node.selectablePaths.length === 0"
                  (click)="$event.stopPropagation()" (change)="onToggle($event, row.node.selectablePaths)">
                {{ row.node.name }}/ <span class="meta">{{ row.node.selectablePaths.length }} archivos</span>
              </summary>
              @if (expanded().has(row.node.path)) {
                @if (row.node.children.length) {
                  <app-file-tree [nodes]="row.node.children" [selected]="selected()" [disabled]="disabled()" (toggle)="toggle.emit($event)" />
                } @else { <p class="meta">Carpeta vacia (no se publican carpetas sin archivos).</p> }
              }
            </details>
          } @else {
            <label [class.ignored]="row.node.ignored" [title]="row.node.remote_path || row.node.reason || row.node.path">
              <input type="checkbox" [attr.aria-label]="'Seleccionar archivo ' + row.node.path"
                [checked]="row.checked" [disabled]="disabled() || row.node.ignored"
                (change)="onToggle($event, row.node.selectablePaths)">
              <span>{{ row.node.name }}{{ row.node.kind === 'directory' ? '/' : '' }}</span>
              @if (row.node.ignored) { <span class="meta">{{ row.node.reason }}</span> }
              @else {
                <span class="badge" [class.changed]="row.node.changed">{{ row.node.changed ? 'Nuevo / cambiado' : 'Sin cambios' }}</span>
                <span class="meta">{{ row.node.size }} bytes</span>
              }
            </label>
          }
        </li>
      }
    </ul>
  `,
  styleUrl: './file-tree.css',
})
export class FileTree {
  readonly nodes = input.required<SelectableNode[]>();
  readonly selected = input.required<ReadonlySet<string>>();
  readonly disabled = input(false);
  readonly toggle = output<{ paths: string[]; checked: boolean }>();
  readonly expanded = signal<ReadonlySet<string>>(new Set());
  readonly rows = computed(() => {
    const selected = this.selected();
    return this.nodes().map((node) => {
      const count = node.selectablePaths.filter((path) => selected.has(path)).length;
      return { node, checked: count > 0 && count === node.selectablePaths.length, partial: count > 0 && count < node.selectablePaths.length };
    });
  });

  onToggle(event: Event, paths: string[]): void {
    if (event.target instanceof HTMLInputElement && !this.disabled()) {
      this.toggle.emit({ paths, checked: event.target.checked });
    }
  }

  setOpen(event: Event, path: string): void {
    if (event.target instanceof HTMLDetailsElement) {
      const expanded = new Set(this.expanded());
      if (event.target.open) expanded.add(path);
      else expanded.delete(path);
      this.expanded.set(expanded);
    }
  }
}
