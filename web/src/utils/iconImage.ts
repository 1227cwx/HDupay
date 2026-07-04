export function preventImageDrag(event: DragEvent) {
  event.preventDefault()
}

export const nonDraggableImageStyle: any = {
  display: 'block',
  userSelect: 'none',
  WebkitUserDrag: 'none',
  pointerEvents: 'none'
}

export const nonDraggableImageProps: any = {
  draggable: false,
  onDragstart: preventImageDrag,
  style: nonDraggableImageStyle
}

export function nonDraggableImageWrapperStyle(size: number): any {
  return {
    flex: '0 0 auto',
    width: `${size}px`,
    height: `${size}px`,
    lineHeight: 0,
    display: 'inline-flex',
    alignItems: 'center',
    userSelect: 'none',
    WebkitUserDrag: 'none'
  }
}
