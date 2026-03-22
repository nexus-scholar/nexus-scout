import * as React from "react"
import { cn } from "@/lib/utils"

type TabsContextType = {
  activeTab: string | undefined;
  setActiveTab: (value: string) => void;
};

const TabsContext = React.createContext<TabsContextType | undefined>(undefined);

const useTabs = () => {
  const context = React.useContext(TabsContext);
  if (!context) {
    throw new Error("Tabs components must be used within a Tabs provider");
  }
  return context;
};

const Tabs = ({ children, defaultValue, className, ...props }: { children: React.ReactNode, defaultValue?: string, className?: string } & React.HTMLAttributes<HTMLDivElement>) => {
  const [activeTab, setActiveTab] = React.useState<string | undefined>(defaultValue);

  return (
    <TabsContext.Provider value={{ activeTab, setActiveTab }}>
      <div className={cn("flex flex-col gap-2", className)} {...props}>
        {children}
      </div>
    </TabsContext.Provider>
  )
}

const TabsList = ({ children, className, ...props }: { children: React.ReactNode, className?: string } & React.HTMLAttributes<HTMLDivElement>) => (
  <div {...props} className={cn("inline-flex h-10 items-center justify-center rounded-md bg-muted p-1 text-muted-foreground", className)}>
    {children}
  </div>
)

const TabsTrigger = ({ value, children, className, ...props }: { value: string, children: React.ReactNode, className?: string } & React.ButtonHTMLAttributes<HTMLButtonElement>) => {
  const { activeTab, setActiveTab } = useTabs();
  
  return (
    <button
      {...props}
      type="button"
      onClick={(e) => {
          setActiveTab(value);
          if (props.onClick) props.onClick(e);
      }}
      className={cn(
        "inline-flex items-center justify-center whitespace-nowrap rounded-sm px-3 py-1.5 text-sm font-medium ring-offset-background transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50",
        activeTab === value ? "bg-background text-foreground shadow-sm" : "hover:text-foreground",
        className
      )}
    >
      {children}
    </button>
  );
}

const TabsContent = ({ value, children, className, ...props }: { value: string, children: React.ReactNode, className?: string } & React.HTMLAttributes<HTMLDivElement>) => {
  const { activeTab } = useTabs();
  
  if (activeTab !== value) return null;

  return (
    <div {...props} className={cn("ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2", className)}>
      {children}
    </div>
  )
}

export { Tabs, TabsList, TabsTrigger, TabsContent }
