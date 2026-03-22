
import sys
import os
from pathlib import Path

# Add src to path so we can import nexus
sys.path.append(os.path.join(os.getcwd(), "src"))

try:
    from nexus.core.models import Query
    from nexus.core.config import ProviderConfig
    from nexus.providers import get_provider
except ImportError as e:
    print(f"Import Error: {e}")
    sys.exit(1)

def run_test_search(provider_name, query_text):
    print(f"\n--- Testing {provider_name} with query: {query_text} ---")
    config = ProviderConfig(enabled=True, rate_limit=1.0)
    
    try:
        provider = get_provider(provider_name, config)
        query = Query(text=query_text, max_results=5)
        
        # Generator to list for easier count
        results = []
        for i, doc in enumerate(provider.search(query)):
            results.append(doc)
            print(f"{i+1}. {doc.title} ({doc.year}) - {doc.url}")
            if doc.abstract:
                print(f"   Abstract length: {len(doc.abstract)}")
            else:
                print("   No abstract found")
            if i >= 4: # limit to 5
                break
                
        print(f"Found {len(results)} results")
                
    except Exception as e:
        print(f"Error testing {provider_name}: {e}")
        import traceback
        traceback.print_exc()

if __name__ == "__main__":
    run_test_search("openalex", "heart")
    run_test_search("arxiv", "heart")
