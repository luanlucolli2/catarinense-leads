import * as React from "react"
import { X } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { Button } from "@/components/ui/button"
import { cn } from "@/lib/utils"

type MultiSelectOption = string | { value: string; label: string }

interface MultiSelectProps {
  options: MultiSelectOption[]
  selected: string[]
  onChange: (selected: string[]) => void
  placeholder?: string
  className?: string
  disabled?: boolean

  /** Placeholder do campo de busca dentro da lista */
  searchPlaceholder?: string
  /** Mensagem quando não há resultados */
  emptyMessage?: string

  /**
   * Classes aplicadas AO QUADRADO quando marcado.
   * Por padrão usa o mesmo azul do botão de confirmar: bg-blue-600 / border-blue-600 / text-white
   */
  checkedClasses?: string
  /**
   * Classes aplicadas AO QUADRADO quando desmarcado.
   * Por padrão mantém a borda azul com opacidade.
   */
  uncheckedClasses?: string

  /** Classes do placeholder dentro do botão (não-herda bold do Button) */
  placeholderClassName?: string
}

export function MultiSelect({
  options,
  selected,
  onChange,
  placeholder = "Selecionar...",
  className,
  disabled = false,
  searchPlaceholder = "Pesquisar...",
  emptyMessage = "Nenhum item encontrado.",
  checkedClasses = "bg-blue-600 border-blue-600 text-white",
  uncheckedClasses = "border-blue-600 opacity-50 [&_svg]:invisible",
  placeholderClassName = "truncate font-normal text-muted-foreground",
}: MultiSelectProps) {
  const [open, setOpen] = React.useState(false)
  const normalizedOptions = React.useMemo(
    () =>
      options.map((option) =>
        typeof option === "string"
          ? { value: option, label: option }
          : option
      ),
    [options]
  )
  const optionLabelMap = React.useMemo(
    () => new Map(normalizedOptions.map((option) => [option.value, option.label])),
    [normalizedOptions]
  )

  const handleUnselect = (item: string) => {
    if (disabled) return
    onChange(selected.filter((s) => s !== item))
  }

  const handleSelect = (item: string) => {
    if (disabled) return
    if (selected.includes(item)) {
      handleUnselect(item)
    } else {
      onChange([...selected, item])
    }
  }

  const boxBase =
    "mr-2 flex h-4 w-4 items-center justify-center rounded-sm border transition-colors"

  return (
    <Popover
      open={disabled ? false : open}
      onOpenChange={(next) => {
        if (disabled) return
        setOpen(next)
      }}
    >
      <PopoverTrigger asChild>
        <Button
          variant="outline"
          role="combobox"
          aria-expanded={open}
          disabled={disabled}
          className={cn("w-full justify-between", className)}
        >
          <div className="flex flex-1 min-w-0 gap-1 flex-wrap text-left">
            {selected.length > 0 ? (
              selected.map((item) => (
                <Badge
                  variant="secondary"
                  key={item}
                  className="mr-1 mb-1 cursor-pointer"
                  onClick={(e) => {
                    e.stopPropagation()
                    if (disabled) return
                    handleUnselect(item)
                  }}
                >
                  {optionLabelMap.get(item) ?? item}
                  <X className="ml-1 h-3 w-3" />
                </Badge>
              ))
            ) : (
              // 🔹 placeholder sem negrito
              <span className={placeholderClassName}>{placeholder}</span>
            )}
          </div>
          <X
            className={cn(
              "ml-2 h-4 w-4 shrink-0 opacity-0 transition-opacity",
              selected.length > 0 && "opacity-40 hover:opacity-70"
            )}
            onClick={(e) => {
              e.stopPropagation()
              if (disabled) return
              if (selected.length) onChange([])
            }}
          />
        </Button>
      </PopoverTrigger>

      <PopoverContent className="w-full p-0" align="start">
        <Command>
          <CommandInput placeholder={searchPlaceholder} />
          <CommandList>
            <CommandEmpty>{emptyMessage}</CommandEmpty>
            <CommandGroup>
              {normalizedOptions.map((option) => {
                const isChecked = selected.includes(option.value)
                return (
                  <CommandItem
                    key={option.value}
                    onSelect={() => {
                      if (disabled) return
                      handleSelect(option.value)
                    }}
                    className={cn("cursor-pointer", disabled && "pointer-events-none opacity-60")}
                  >
                    <div
                      className={cn(
                        boxBase,
                        isChecked ? checkedClasses : uncheckedClasses
                      )}
                      aria-hidden="true"
                    >
                      <X className="h-3 w-3" />
                    </div>
                    <span className="truncate">{option.label}</span>
                  </CommandItem>
                )
              })}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
